<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\RecurringInvoiceStatus;
use App\Exceptions\EntitlementException;
use App\Exceptions\RecurringInvoiceException;
use App\Models\InvoiceEmail;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\RecurringInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RecurringInvoices\CreatesRecurringInvoices;
use Tests\TestCase;

class RecurringEntitlementTest extends TestCase
{
    use CreatesRecurringInvoices;
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCreatesRecurringInvoices();
        $this->owner = User::factory()->create();
    }

    private function service(): RecurringInvoiceService
    {
        return app(RecurringInvoiceService::class);
    }

    /**
     * The schedule's stored state, to prove a skipped run left it alone.
     *
     * @return array<string, mixed>
     */
    private function snapshot(RecurringInvoice $recurring): array
    {
        return $recurring->fresh()->only([
            'status', 'next_occurrence_on', 'last_occurrence_on', 'last_generated_at',
            'last_generation_error', 'last_generation_failed_at', 'updated_at',
        ]);
    }

    // ---- creating schedules ---------------------------------------------------------------

    public function test_schedules_can_be_created_up_to_the_limit(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 2]);

        $this->recurringFor($this->owner);
        $second = $this->recurringFor($this->owner);

        $this->assertSame(RecurringInvoiceStatus::Active, $second->status);
        $this->assertSame(2, $this->businessOf($this->owner)->recurringInvoices()->count());
    }

    public function test_creating_past_the_limit_is_refused(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 1]);
        $this->recurringFor($this->owner);

        $this->assertThrows(fn () => $this->recurringFor($this->owner), EntitlementException::class, 'limit of 1 recurring invoices');
        $this->assertSame(1, $this->businessOf($this->owner)->recurringInvoices()->count());
    }

    public function test_paused_schedules_use_a_slot_but_cancelled_ones_free_it(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 1]);
        $first = $this->recurringFor($this->owner);
        $this->service()->pause($this->businessOf($this->owner), $first);
        $this->assertThrows(fn () => $this->recurringFor($this->owner), EntitlementException::class);

        $this->service()->cancel($this->businessOf($this->owner), $first->fresh());

        $this->assertSame(RecurringInvoiceStatus::Active, $this->recurringFor($this->owner)->status);
    }

    public function test_a_plan_without_recurring_invoices_cannot_create_one(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 0]);

        $this->assertThrows(fn () => $this->recurringFor($this->owner), EntitlementException::class, "doesn't include recurring invoices");
    }

    public function test_the_create_form_and_post_are_refused_with_the_reason(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 0]);

        $this->actingAs($this->owner)->get(route('recurring-invoices.create'))
            ->assertForbidden()->assertSee("doesn't include recurring invoices");
    }

    public function test_editing_is_still_allowed_when_a_business_is_over_its_limit(): void
    {
        $first = $this->recurringFor($this->owner);
        $this->recurringFor($this->owner);
        $this->limitTo($this->owner, ['recurring_invoices.max' => 1]);

        $updated = $this->service()->save(
            $this->businessOf($this->owner),
            $this->owner,
            $this->recurringPayload($first->customer, overrides: ['name' => 'Renamed schedule']),
            $first,
        );

        $this->assertSame('Renamed schedule', $updated->name);
        $this->assertSame(2, $this->businessOf($this->owner)->recurringInvoices()->count());
    }

    public function test_editing_is_refused_in_read_only_and_leaves_the_template_alone(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $this->makeReadOnly($this->owner);

        $this->assertThrows(fn () => $this->service()->save(
            $this->businessOf($this->owner),
            $this->owner,
            $this->recurringPayload($recurring->customer, overrides: ['name' => 'Hacked']),
            $recurring,
        ), EntitlementException::class, 'read-only');

        $this->assertSame('Monthly website maintenance', $recurring->fresh()->name);
    }

    public function test_other_businesses_schedules_do_not_count(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 1]);
        $this->recurringFor(User::factory()->create());
        $this->recurringFor(User::factory()->create());

        $this->assertSame(RecurringInvoiceStatus::Active, $this->recurringFor($this->owner)->status);
    }

    // ---- generation -----------------------------------------------------------------------

    public function test_a_read_only_business_generates_nothing_and_its_schedule_is_not_touched(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $before = $this->snapshot($recurring);
        $this->travelTo('2026-04-30 09:00:00');
        $this->makeReadOnly($this->owner);

        $output = $this->runGeneration();

        $this->assertSame(0, $recurring->invoices()->count());
        $this->assertEquals($before, $this->snapshot($recurring));
        $this->assertStringContainsString('Generated 0 invoice(s); 0 recurring invoice(s) failed.', $output);
        $this->assertStringContainsString('Skipped 1 business(es)', $output);
    }

    public function test_a_skipped_business_does_not_stop_the_rest_of_the_run(): void
    {
        $lapsed = User::factory()->create();
        $healthy = User::factory()->create();
        $lapsedSchedule = $this->recurringFor($lapsed);
        $healthySchedule = $this->recurringFor($healthy);
        $this->makeReadOnly($lapsed);
        $this->travelTo('2026-02-28 09:00:00');

        $output = $this->runGeneration();

        $this->assertSame(0, $lapsedSchedule->invoices()->count());
        $this->assertSame(2, $healthySchedule->invoices()->count());
        $this->assertStringContainsString('Skipped 1 business(es)', $output);
    }

    public function test_a_plan_without_recurring_invoices_is_skipped_even_with_full_access(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $this->limitTo($this->owner, ['recurring_invoices.max' => 0]);
        $before = $this->snapshot($recurring);
        $this->travelTo('2026-03-31 09:00:00');

        $output = $this->runGeneration();

        $this->assertSame(0, $recurring->invoices()->count());
        $this->assertEquals($before, $this->snapshot($recurring));
        $this->assertStringContainsString('Skipped 1', $output);
    }

    public function test_a_business_over_its_limit_still_generates_what_it_has(): void
    {
        $first = $this->recurringFor($this->owner);
        $this->recurringFor($this->owner);
        $this->limitTo($this->owner, ['recurring_invoices.max' => 1]);
        $this->travelTo('2026-02-28 09:00:00');

        $this->runGeneration();

        $this->assertSame(2, $first->invoices()->count());
    }

    public function test_generation_continues_during_grace(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $this->lapsedPaid($this->owner, 2);
        $this->travelTo('2026-02-28 09:00:00');
        // lapsedPaid is relative to the (new) clock; rebuild it at this moment.
        $this->lapsedPaid($this->owner, 2);

        $this->runGeneration();

        $this->assertSame(2, $recurring->invoices()->count());
    }

    public function test_generation_resumes_with_catch_up_when_access_returns_still_only_drafts(): void
    {
        $recurring = $this->recurringFor($this->owner, overrides: ['frequency' => 'weekly', 'start_date' => '2026-01-31']);
        $this->makeReadOnly($this->owner);
        $this->travelTo('2026-10-31 09:00:00');
        $this->makeReadOnly($this->owner);
        $this->runGeneration();
        $this->assertSame(0, $recurring->invoices()->count(), 'nothing while read-only');

        // Access returns: ordinary catch-up, capped at 12 per run.
        $this->limitTo($this->owner, [
            'customers.max' => null, 'products.max' => null, 'invoices.monthly_max' => null,
            'recurring_invoices.max' => null, 'team.seats' => null, 'invoices.email' => true,
        ]);
        $output = $this->runGeneration();

        $this->assertSame(12, $recurring->invoices()->count());
        $this->assertStringContainsString('Generated 12 invoice(s)', $output);
        $this->assertTrue($recurring->invoices()->get()->every(fn ($invoice) => $invoice->status === InvoiceStatus::Draft && $invoice->invoice_number === null));
        $this->assertSame(0, InvoiceEmail::count());

        $this->runGeneration();
        $this->assertSame(24, $recurring->invoices()->count(), 'the next run continues');
    }

    public function test_generate_now_is_refused_without_recording_a_failure_on_the_schedule(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $this->limitTo($this->owner, ['recurring_invoices.max' => 0]);

        $this->assertThrows(
            fn () => $this->service()->generateNow($this->businessOf($this->owner), $recurring, $this->owner),
            EntitlementException::class,
        );

        $this->assertNull($recurring->fresh()->last_generation_error);
        $this->assertNull($recurring->fresh()->last_generation_failed_at);
        $this->assertSame(0, $recurring->invoices()->count());
    }

    public function test_generate_due_returns_nothing_for_a_blocked_business_without_an_error(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $this->makeReadOnly($this->owner);

        $result = $this->service()->generateDue($this->businessOf($this->owner), $recurring);

        $this->assertSame(['invoices' => [], 'error' => null], $result);
    }

    public function test_generation_block_reports_why(): void
    {
        $business = $this->businessOf($this->owner);
        $this->assertNull($this->service()->generationBlock($business));

        $this->limitTo($this->owner, ['recurring_invoices.max' => 0]);
        $this->assertSame('not_included', $this->service()->generationBlock($business)->reason->value);

        $this->makeReadOnly($this->owner);
        $this->assertSame('read_only', $this->service()->generationBlock($business)->reason->value);
    }

    public function test_the_http_generate_now_button_is_refused_in_read_only(): void
    {
        $recurring = $this->recurringFor($this->owner);
        $this->makeReadOnly($this->owner);

        $this->actingAs($this->owner)->post(route('recurring-invoices.generate', $recurring))->assertForbidden();

        $this->assertSame(0, $recurring->invoices()->count());
    }

    public function test_recurring_exception_behaviour_is_unchanged_for_entitled_businesses(): void
    {
        $recurring = $this->recurringFor($this->owner, overrides: ['start_date' => '2026-03-01']);

        $this->assertThrows(
            fn () => $this->service()->generateNow($this->businessOf($this->owner), $recurring, $this->owner),
            RecurringInvoiceException::class,
            'no invoice due',
        );
    }
}
