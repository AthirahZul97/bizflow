<?php

namespace Tests\Feature\RecurringInvoices;

use App\Enums\RecurringInvoiceStatus;
use App\Exceptions\RecurringInvoiceException;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\RecurringInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pause, resume, cancel, delete and "Generate now".
 */
class RecurringInvoiceLifecycleTest extends TestCase
{
    use CreatesRecurringInvoices;
    use RefreshDatabase;

    private function service(): RecurringInvoiceService
    {
        return app(RecurringInvoiceService::class);
    }

    public function test_pausing_stops_generation_and_resuming_starts_it_again(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['start_date' => '2026-02-15']);

        $this->actingAs($user)->post(route('recurring-invoices.pause', $recurring))
            ->assertRedirect(route('recurring-invoices.show', $recurring))
            ->assertSessionHas('status', 'Recurring invoice paused. No invoices are generated while it is paused.');
        $this->assertSame(RecurringInvoiceStatus::Paused, $recurring->fresh()->status);
        $this->assertNotNull($recurring->fresh()->paused_at);

        $this->travelTo('2026-02-15 09:00:00');
        $this->runGeneration();
        $this->assertSame(0, Invoice::count());

        $this->actingAs($user)->post(route('recurring-invoices.resume', $recurring))
            ->assertSessionHas('status', 'Recurring invoice resumed. The next invoice is for 15 Feb 2026.');
        $this->assertSame(RecurringInvoiceStatus::Active, $recurring->fresh()->status);
        $this->assertNull($recurring->fresh()->paused_at);

        $this->runGeneration();
        $this->assertSame(1, Invoice::count());
    }

    public function test_resuming_skips_the_occurrences_missed_while_paused(): void
    {
        $user = User::factory()->create();
        $business = $this->businessOf($user);
        $recurring = $this->recurringFor($user);
        $this->service()->generateNext($business, $recurring);
        $this->service()->pause($business, $recurring);

        // February, March and April fall due while it is paused.
        $this->travelTo('2026-05-10 09:00:00');
        $this->runGeneration();
        $this->assertSame(1, $recurring->invoices()->count());

        $resumed = $this->service()->resume($business, $recurring->fresh());

        $this->assertSame('2026-05-31', $resumed->next_occurrence_on->toDateString());
        $this->runGeneration();
        $this->assertSame(1, $recurring->invoices()->count(), 'Nothing is back-filled for the paused months.');

        $this->travelTo('2026-05-31 09:00:00');
        $this->runGeneration();
        $this->assertSame(['2026-01-31', '2026-05-31'],
            $recurring->invoices()->orderBy('id')->get()->map(fn ($i) => $i->recurring_occurrence_on->toDateString())->all());
    }

    public function test_resuming_on_an_occurrence_date_generates_that_occurrence(): void
    {
        $user = User::factory()->create();
        $business = $this->businessOf($user);
        $recurring = $this->recurringFor($user);
        $this->service()->pause($business, $recurring);

        $this->travelTo('2026-02-28 09:00:00');
        $resumed = $this->service()->resume($business, $recurring->fresh());

        $this->assertSame('2026-02-28', $resumed->next_occurrence_on->toDateString());
    }

    public function test_cancelling_is_confirmed_first_and_is_final(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);
        $before = $invoice->fresh()->getAttributes();

        $this->actingAs($user)->get(route('recurring-invoices.cancel.confirm', $recurring))
            ->assertOk()
            ->assertSee('No more invoices will be generated from it.');
        $this->assertSame(RecurringInvoiceStatus::Active, $recurring->fresh()->status);

        $this->actingAs($user)->post(route('recurring-invoices.cancel', $recurring))
            ->assertRedirect(route('recurring-invoices.show', $recurring))
            ->assertSessionHas('status', 'Recurring invoice cancelled. Invoices already generated are unchanged.');

        $recurring->refresh();
        $this->assertSame(RecurringInvoiceStatus::Cancelled, $recurring->status);
        $this->assertNotNull($recurring->cancelled_at);
        $this->assertSame($before, $invoice->fresh()->getAttributes(), 'Generated invoices are untouched.');

        // Terminal: nothing can be done with it any more, and nothing is generated.
        foreach (['pause', 'resume', 'cancel', 'generate'] as $action) {
            $this->actingAs($user)->post(route("recurring-invoices.{$action}", $recurring))->assertForbidden();
        }
        $this->actingAs($user)->get(route('recurring-invoices.edit', $recurring))
            ->assertForbidden()->assertSee('Cancelled recurring invoices can’t be edited.');
        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring), $this->recurringPayload($recurring->customer))->assertForbidden();
        $this->assertThrows(fn () => $this->service()->resume($this->businessOf($user), $recurring), RecurringInvoiceException::class);

        $this->travelTo('2026-12-31 09:00:00');
        $this->runGeneration();
        $this->assertSame(1, $recurring->invoices()->count());
    }

    public function test_actions_in_the_wrong_state_are_refused_with_a_reason(): void
    {
        $user = User::factory()->create();
        $active = RecurringInvoice::factory()->ownedBy($user)->create();
        $paused = RecurringInvoice::factory()->ownedBy($user)->paused()->create();

        $this->actingAs($user)->post(route('recurring-invoices.resume', $active))
            ->assertForbidden()->assertSee('Only paused recurring invoices can be resumed.');
        $this->actingAs($user)->post(route('recurring-invoices.pause', $paused))
            ->assertForbidden()->assertSee('Only active recurring invoices can be paused.');
        $this->actingAs($user)->post(route('recurring-invoices.generate', $paused))
            ->assertForbidden()->assertSee('Only active recurring invoices can generate invoices.');
    }

    public function test_a_schedule_that_never_generated_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['start_date' => '2026-03-01']);

        $this->actingAs($user)->get(route('recurring-invoices.delete', $recurring))
            ->assertOk()->assertSee('It has not generated any invoices');
        $this->assertModelExists($recurring);

        $this->actingAs($user)->delete(route('recurring-invoices.destroy', $recurring))
            ->assertRedirect(route('recurring-invoices.index'))
            ->assertSessionHas('status', 'Recurring invoice deleted.');

        $this->assertModelMissing($recurring);
        $this->assertDatabaseCount('recurring_invoice_items', 0);
    }

    public function test_a_schedule_that_has_generated_can_only_be_cancelled(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);

        $this->actingAs($user)->get(route('recurring-invoices.delete', $recurring))
            ->assertForbidden()->assertSee('Cancel it instead.');
        $this->actingAs($user)->delete(route('recurring-invoices.destroy', $recurring))->assertForbidden();
        $this->assertThrows(fn () => $this->service()->delete($this->businessOf($user), $recurring), RecurringInvoiceException::class);
        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))->assertDontSee(route('recurring-invoices.delete', $recurring));

        $this->assertModelExists($recurring);
        $this->assertModelExists($invoice);

        // Even after its generated draft is deleted, the schedule's history stands.
        app(InvoiceService::class)->deleteDraft($invoice);
        $this->actingAs($user)->delete(route('recurring-invoices.destroy', $recurring))->assertForbidden();
    }

    public function test_the_database_keeps_a_schedule_that_has_invoices(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $this->service()->generateNext($this->businessOf($user), $recurring);

        $this->assertThrows(fn () => RecurringInvoice::whereKey($recurring->id)->delete(), QueryException::class);
        $this->assertModelExists($recurring);
    }

    public function test_a_finished_schedule_resumes_when_its_end_date_is_extended(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['end_date' => '2026-01-31']);
        $this->service()->generateNext($this->businessOf($user), $recurring);

        $this->assertTrue($recurring->fresh()->isFinished());
        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))->assertSee('Finished');

        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring),
            $this->recurringPayload($recurring->customer, overrides: ['end_date' => '2026-03-31']))->assertSessionHasNoErrors();

        $this->assertFalse($recurring->fresh()->isFinished());
        $this->travelTo('2026-03-31 09:00:00');
        $this->runGeneration();
        $this->assertSame(3, $recurring->invoices()->count());
    }

    public function test_generate_now_creates_the_due_occurrence_as_a_draft_by_the_user(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);

        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertSee('1 invoice is due')
            ->assertSee('Generate now (31 Jan 2026)');

        $response = $this->actingAs($user)->post(route('recurring-invoices.generate', $recurring));

        $invoice = Invoice::sole();
        $response->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status', 'Draft invoice generated for 31 Jan 2026.');
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame($recurring->id, $invoice->recurring_invoice_id);
        $this->assertSame('2026-02-28', $recurring->fresh()->next_occurrence_on->toDateString());

        // The scheduler finds nothing left for that occurrence.
        $this->runGeneration();
        $this->assertSame(1, Invoice::count());
    }

    public function test_generate_now_never_generates_an_occurrence_early(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['start_date' => '2026-02-01']);

        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertDontSee('data-generate-now', false)
            ->assertDontSee('data-due-notice', false);

        $this->actingAs($user)->from(route('recurring-invoices.show', $recurring))
            ->post(route('recurring-invoices.generate', $recurring))
            ->assertRedirect(route('recurring-invoices.show', $recurring))
            ->assertSessionHas('error', 'There is no invoice due to generate right now.');

        $this->assertSame(0, Invoice::count());
        $this->assertSame('2026-02-01', $recurring->fresh()->next_occurrence_on->toDateString());
    }

    public function test_generate_now_twice_generates_only_what_is_due(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);

        $this->actingAs($user)->post(route('recurring-invoices.generate', $recurring))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('recurring-invoices.generate', $recurring))
            ->assertSessionHas('error', 'There is no invoice due to generate right now.');

        $this->assertSame(1, Invoice::count());
    }

    public function test_generate_now_reports_a_failure_and_records_it(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Web hosting']);
        $recurring = $this->recurringFor($user, items: [
            ['product_id' => $product->id, 'name' => null, 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => null],
        ]);
        $product->update(['is_active' => false]);

        $this->actingAs($user)->from(route('recurring-invoices.show', $recurring))
            ->post(route('recurring-invoices.generate', $recurring))
            ->assertRedirect(route('recurring-invoices.show', $recurring))
            ->assertSessionHas('error', fn ($message) => str_starts_with($message, 'The product “Web hosting” is inactive.'));

        $this->assertSame(0, Invoice::count());
        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertSee('The last invoice could not be generated')
            ->assertSee('The product “Web hosting” is inactive.');
        $this->actingAs($user)->get(route('recurring-invoices.index'))->assertSee('Generation failed');
    }

    public function test_generate_now_is_throttled(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['start_date' => '2026-02-01']);

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post(route('recurring-invoices.generate', $recurring))->assertStatus(302);
        }

        $this->actingAs($user)->post(route('recurring-invoices.generate', $recurring))->assertStatus(429);
    }

    public function test_missed_occurrences_are_shown_on_the_list_and_page(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $this->travelTo('2026-04-30 09:00:00');

        $this->actingAs($user)->get(route('recurring-invoices.index'))->assertSee('4 due');
        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertSee('4 invoices are due')
            ->assertSee('(from 31 Jan 2026)')
            ->assertSee('Generate now (31 Jan 2026)');

        $this->runGeneration();

        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertDontSee('data-due-notice', false)
            ->assertSee('for 30 Apr 2026')
            ->assertSee('Invoice for 30 Apr 2026');
    }

    public function test_a_generated_invoice_links_back_to_its_recurring_invoice(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);
        $ordinary = Invoice::factory()->ownedBy($user)->create();

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Generated from the recurring invoice')
            ->assertSee(route('recurring-invoices.show', $recurring))
            ->assertSee('for 31 Jan 2026.');
        $this->actingAs($user)->get(route('invoices.show', $ordinary))->assertDontSee('Generated from the recurring invoice');

        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertSee(route('invoices.show', $invoice))
            ->assertSee('Draft invoice');
    }
}
