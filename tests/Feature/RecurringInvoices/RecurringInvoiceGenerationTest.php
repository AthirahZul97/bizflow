<?php

namespace Tests\Feature\RecurringInvoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\RecurringInvoiceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * Generating ordinary draft invoices from recurring invoices: what is created,
 * when, exactly once, and what happens when it cannot be created.
 */
class RecurringInvoiceGenerationTest extends TestCase
{
    use CreatesRecurringInvoices;
    use RefreshDatabase;

    private function service(): RecurringInvoiceService
    {
        return app(RecurringInvoiceService::class);
    }

    public function test_a_due_occurrence_becomes_an_ordinary_draft_invoice(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, [
            'name' => 'Aisyah Rahman', 'company_name' => 'Rahman Trading', 'email' => 'aisyah@example.com', 'city' => 'Ipoh',
        ]);
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Hosting', 'selling_price' => '35.50']);
        $recurring = $this->recurringFor($user, $customer, [
            ['product_id' => null, 'name' => 'Design', 'description' => 'Monthly design', 'unit' => 'hour', 'quantity' => '2', 'unit_price' => '1250.50'],
            ['product_id' => $product->id, 'name' => 'Hosting', 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => '0.10'],
        ], ['discount_amount' => '1.10', 'tax_label' => 'SST', 'tax_rate' => '6', 'notes' => 'Thank you.', 'payment_terms_days' => '14']);

        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);

        $invoice = Invoice::findOrFail($invoice->id);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->invoice_sequence);
        $this->assertSame($this->businessOf($user)->id, $invoice->business_id);
        $this->assertNull($invoice->created_by, 'System-generated invoices have no creator.');
        $this->assertSame($recurring->id, $invoice->recurring_invoice_id);
        $this->assertSame('2026-01-31', $invoice->recurring_occurrence_on->toDateString());

        // Dated on the occurrence, due after the payment terms.
        $this->assertSame('2026-01-31', $invoice->issue_date->toDateString());
        $this->assertSame('2026-02-14', $invoice->due_date->toDateString());

        // The customer's details are copied onto the invoice, like any invoice.
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame(['Aisyah Rahman', 'Rahman Trading', 'aisyah@example.com', 'Ipoh'],
            [$invoice->customer_name, $invoice->customer_company_name, $invoice->customer_email, $invoice->customer_city]);

        // Exact totals from the shared calculator: 2501.00 + 0.10 - 1.10 = 2500.00, + 6% = 2650.00.
        $this->assertSame(['2501.10', '1.10', '6.00', '150.00', '2650.00', 'SST', 'Thank you.', 'MYR'], [
            $invoice->subtotal, $invoice->discount_amount, $invoice->tax_rate, $invoice->tax_amount, $invoice->total,
            $invoice->tax_label, $invoice->notes, $invoice->currency_code,
        ]);

        [$first, $second] = $invoice->items;
        $this->assertSame(['Design', 'Monthly design', 'hour', '2.00', '1250.50', '2501.00', 1, null],
            [$first->name, $first->description, $first->unit, $first->quantity, $first->unit_price, $first->line_total, $first->position, $first->product_id]);
        $this->assertSame(['Hosting', '1.00', '0.10', '0.10', 2, $product->id],
            [$second->name, $second->quantity, $second->unit_price, $second->line_total, $second->position, $second->product_id]);
    }

    public function test_generating_advances_the_schedule_from_the_start_date(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);

        $this->service()->generateNext($this->businessOf($user), $recurring);

        $recurring->refresh();
        $this->assertSame('2026-02-28', $recurring->next_occurrence_on->toDateString());
        $this->assertSame('2026-01-31', $recurring->last_occurrence_on->toDateString());
        $this->assertNotNull($recurring->last_generated_at);

        $this->travelTo('2026-03-31 09:00:00');
        $this->runGeneration();

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'],
            $recurring->invoices()->orderBy('id')->get()->map(fn ($i) => $i->recurring_occurrence_on->toDateString())->all());
        $this->assertSame('2026-04-30', $recurring->fresh()->next_occurrence_on->toDateString());
    }

    public function test_nothing_is_generated_before_the_occurrence_date(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['start_date' => '2026-02-01']);

        $this->assertNull($this->service()->generateNext($this->businessOf($user), $recurring));
        $this->runGeneration();
        $this->assertSame(0, Invoice::count());

        $this->travelTo('2026-02-01 00:10:00');
        $this->runGeneration();

        $this->assertSame(1, Invoice::count());
    }

    public function test_paused_cancelled_and_finished_schedules_generate_nothing(): void
    {
        $user = User::factory()->create();
        $business = $this->businessOf($user);
        $paused = RecurringInvoice::factory()->ownedBy($user)->paused()->create();
        $cancelled = RecurringInvoice::factory()->ownedBy($user)->cancelled()->create();
        $finished = RecurringInvoice::factory()->ownedBy($user)->create(['start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'next_occurrence_on' => '2026-02-01']);

        foreach ([$paused, $cancelled, $finished] as $recurring) {
            $this->assertNull($this->service()->generateNext($business, $recurring));
        }
        $this->runGeneration();

        $this->assertSame(0, Invoice::count());
    }

    public function test_the_end_date_is_inclusive(): void
    {
        $user = User::factory()->create();
        $untilApril30 = $this->recurringFor($user, overrides: ['end_date' => '2026-04-30']);
        $untilApril29 = $this->recurringFor($user, overrides: ['end_date' => '2026-04-29']);

        $this->travelTo('2026-12-01 09:00:00');
        $this->runGeneration();

        $dates = fn (RecurringInvoice $r) => $r->invoices()->orderBy('id')->get()->map(fn ($i) => $i->recurring_occurrence_on->toDateString())->all();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $dates($untilApril30));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $dates($untilApril29));
        $this->assertTrue($untilApril30->fresh()->isFinished());
        $this->assertTrue($untilApril29->fresh()->isFinished());
    }

    public function test_weekly_and_yearly_schedules_generate_on_their_dates(): void
    {
        $this->travelTo('2028-02-29 09:00:00');
        $user = User::factory()->create();
        $weekly = $this->recurringFor($user, overrides: ['frequency' => 'weekly', 'end_date' => '2028-03-14']);
        $yearly = $this->recurringFor($user, overrides: ['frequency' => 'yearly']);

        $this->travelTo('2030-03-01 09:00:00');
        $this->runGeneration();

        $dates = fn (RecurringInvoice $r) => $r->invoices()->orderBy('id')->get()->map(fn ($i) => $i->recurring_occurrence_on->toDateString())->all();
        $this->assertSame(['2028-02-29', '2028-03-07', '2028-03-14'], $dates($weekly));
        // 29 Feb falls back to the 28th in non-leap years.
        $this->assertSame(['2028-02-29', '2029-02-28', '2030-02-28'], $dates($yearly));
    }

    public function test_missed_occurrences_are_caught_up_oldest_first(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);

        // The scheduler was offline for three months.
        $this->travelTo('2026-04-30 09:00:00');
        $output = $this->runGeneration();

        $invoices = $recurring->invoices()->orderBy('id')->get();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $invoices->map(fn ($i) => $i->issue_date->toDateString())->all());
        $this->assertSame(['2026-02-14', '2026-03-14', '2026-04-14', '2026-05-14'], $invoices->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertTrue($invoices->every(fn ($i) => $i->status === InvoiceStatus::Draft));
        $this->assertStringContainsString('Generated 4 invoice(s); 0 recurring invoice(s) failed.', $output);
    }

    public function test_catch_up_is_capped_per_run_and_continues_on_the_next_run(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['frequency' => 'weekly']);

        // 20 weekly occurrences are due.
        $this->travelTo('2026-06-13 09:00:00');
        $this->assertSame(20, $recurring->fresh()->dueCount(today()));

        $this->runGeneration();
        $this->assertSame(RecurringInvoiceService::MAX_OCCURRENCES_PER_RUN, $recurring->invoices()->count());
        $this->assertSame(8, $recurring->fresh()->dueCount(today()));

        $this->runGeneration();
        $this->assertSame(20, $recurring->invoices()->count());
        $this->assertSame(0, $recurring->fresh()->dueCount(today()));
        $this->assertSame(20, $recurring->invoices()->distinct()->count('recurring_occurrence_on'));
    }

    public function test_running_the_scheduler_again_generates_nothing_more(): void
    {
        $user = User::factory()->create();
        $this->recurringFor($user);

        $this->runGeneration();
        $this->runGeneration();
        $output = $this->runGeneration();

        $this->assertSame(1, Invoice::count());
        $this->assertStringContainsString('Generated 0 invoice(s)', $output);
    }

    public function test_the_same_occurrence_cannot_exist_twice_in_the_database(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);
        $copy = Invoice::factory()->ownedBy($user)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $copy->forceFill([
            'recurring_invoice_id' => $recurring->id,
            'recurring_occurrence_on' => $invoice->recurring_occurrence_on->toDateString(),
        ])->save();
    }

    public function test_ordinary_invoices_are_unaffected_by_the_unique_occurrence_key(): void
    {
        $user = User::factory()->create();

        $invoices = Invoice::factory()->count(5)->ownedBy($user)->create();

        $this->assertCount(5, $invoices);
        $this->assertSame(5, Invoice::whereNull('recurring_invoice_id')->whereNull('recurring_occurrence_on')->count());
    }

    public function test_a_stale_pointer_never_creates_a_duplicate(): void
    {
        $user = User::factory()->create();
        $business = $this->businessOf($user);
        $recurring = $this->recurringFor($user);
        $this->service()->generateNext($business, $recurring);

        // Something put the pointer back on an occurrence that already has an invoice.
        $recurring->forceFill(['next_occurrence_on' => '2026-01-31'])->save();

        $this->assertNull($this->service()->generateNext($business, $recurring));

        $this->assertSame(1, $recurring->invoices()->count());
        $this->assertSame('2026-02-28', $recurring->fresh()->next_occurrence_on->toDateString());
    }

    public function test_a_deleted_generated_draft_is_not_generated_again(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);

        app(InvoiceService::class)->deleteDraft($invoice);
        $this->runGeneration();

        $this->assertSame(0, Invoice::count());
        $this->assertTrue($recurring->fresh()->hasGenerated());
    }

    public function test_editing_the_template_never_changes_invoices_already_generated(): void
    {
        $user = User::factory()->create();
        $business = $this->businessOf($user);
        $customer = $this->customerFor($user, ['name' => 'Original Customer', 'email' => 'original@example.com']);
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Original Product', 'selling_price' => '120.00']);
        $recurring = $this->recurringFor($user, $customer, [
            ['product_id' => $product->id, 'name' => null, 'description' => null, 'unit' => null, 'quantity' => '2', 'unit_price' => null],
        ], ['tax_label' => 'SST', 'tax_rate' => '6', 'discount_amount' => '10']);

        $january = $this->service()->generateNext($business, $recurring);
        $before = [$january->fresh()->getAttributes(), $january->items()->get()->map->getAttributes()->all()];

        // Everything changes afterwards: template, product and customer.
        $newCustomer = $this->customerFor($user, ['name' => 'New Customer']);
        $this->service()->save($business, $user, $this->recurringPayload($newCustomer, [
            ['product_id' => null, 'name' => 'New line', 'description' => 'Changed', 'unit' => 'day', 'quantity' => '5', 'unit_price' => '999.00'],
        ], ['name' => 'Changed', 'tax_label' => 'GST', 'tax_rate' => '10', 'discount_amount' => '0', 'notes' => 'New notes']), $recurring);
        $product->update(['name' => 'Renamed Product', 'selling_price' => '500.00']);
        $customer->update(['name' => 'Renamed Customer', 'email' => 'renamed@example.com']);
        $this->service()->pause($business, $recurring);
        $this->service()->resume($business, $recurring->fresh());

        $this->assertSame($before, [$january->fresh()->getAttributes(), $january->items()->get()->map->getAttributes()->all()]);

        // The next occurrence uses the new template.
        $this->travelTo('2026-02-28 09:00:00');
        $february = $this->service()->generateNext($business, $recurring->fresh());
        $this->assertSame(['New Customer', '4995.00', '5494.50', 'GST'],
            [$february->customer_name, $february->subtotal, $february->total, $february->tax_label]);
        $this->assertSame('New line', $february->items->sole()->name);
        $this->assertSame($before[0], $january->fresh()->getAttributes());
    }

    public function test_a_product_price_change_does_not_change_the_template_price(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->create(['selling_price' => '100.00']);
        $recurring = $this->recurringFor($user, items: [
            ['product_id' => $product->id, 'name' => null, 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => null],
        ]);

        $product->update(['selling_price' => '250.00']);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);

        $this->assertSame('100.00', $invoice->items->sole()->unit_price);
        $this->assertSame('100.00', $invoice->total);
    }

    public function test_the_customers_current_details_are_copied_at_generation(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Old Name', 'email' => 'old@example.com']);
        $recurring = $this->recurringFor($user, $customer);

        $customer->update(['name' => 'New Name', 'email' => 'new@example.com']);
        $invoice = $this->service()->generateNext($this->businessOf($user), $recurring);

        $this->assertSame(['New Name', 'new@example.com'], [$invoice->customer_name, $invoice->customer_email]);
    }

    public function test_an_inactive_product_fails_generation_and_is_recorded_without_creating_anything(): void
    {
        $user = User::factory()->create();
        $business = $this->businessOf($user);
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Web hosting']);
        $recurring = $this->recurringFor($user, items: [
            ['product_id' => $product->id, 'name' => null, 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => null],
        ]);
        $product->update(['is_active' => false]);

        $output = $this->runGeneration();

        $recurring->refresh();
        $this->assertSame(0, Invoice::count());
        $this->assertSame('2026-01-31', $recurring->next_occurrence_on->toDateString(), 'The occurrence is still due.');
        $this->assertNull($recurring->last_occurrence_on);
        $this->assertSame('The product “Web hosting” is inactive. Reactivate it, or remove it from this recurring invoice.', $recurring->last_generation_error);
        $this->assertNotNull($recurring->last_generation_failed_at);
        $this->assertStringContainsString('1 recurring invoice(s) failed', $output);

        // Fixed: the next run generates it and clears the error.
        $product->update(['is_active' => true]);
        $this->runGeneration();

        $recurring->refresh();
        $this->assertSame(1, $recurring->invoices()->count());
        $this->assertNull($recurring->last_generation_error);
        $this->assertNull($recurring->last_generation_failed_at);
    }

    public function test_a_failure_part_way_through_catch_up_keeps_what_was_generated_and_stops(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $this->travelTo('2026-04-30 09:00:00');

        // The second invoice cannot be saved.
        $calls = 0;
        Invoice::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('Disk full');
            }
        });
        Exceptions::fake();

        $this->runGeneration();

        $recurring->refresh();
        $this->assertSame(1, $recurring->invoices()->count());
        $this->assertSame('2026-02-28', $recurring->next_occurrence_on->toDateString());
        $this->assertSame('The invoice could not be generated because of an unexpected error. It will be tried again automatically.', $recurring->last_generation_error);
        Exceptions::assertReported(RuntimeException::class);

        // The next run continues from the same occurrence.
        $this->runGeneration();
        $this->assertSame(4, $recurring->invoices()->count());
        $this->assertNull($recurring->fresh()->last_generation_error);
    }

    public function test_a_failed_generation_rolls_back_the_whole_invoice(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, items: [
            ['product_id' => null, 'name' => 'One', 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => '10.00'],
            ['product_id' => null, 'name' => 'Two', 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => '10.00'],
        ]);

        // The invoice and its first line are written, then the second line fails.
        $lines = 0;
        InvoiceItem::creating(function () use (&$lines) {
            if (++$lines === 2) {
                throw new RuntimeException('Failed on the second line');
            }
        });
        Exceptions::fake();

        $this->runGeneration();

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('invoice_items')->count());
        $this->assertSame('2026-01-31', $recurring->fresh()->next_occurrence_on->toDateString());
    }

    public function test_generated_invoices_follow_the_normal_lifecycle_and_numbering(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $this->travelTo('2026-02-28 09:00:00');
        $this->runGeneration();
        [$january, $february] = $recurring->invoices()->orderBy('id')->get();

        // Numbers are assigned on issue, in the order they are issued, by the normal generator.
        $invoices = app(InvoiceService::class);
        $this->assertSame('INV-00001', $invoices->issue($february)->invoice_number);
        $this->assertSame('INV-00002', $invoices->issue($january)->invoice_number);
        $this->assertSame(InvoiceStatus::Paid, $invoices->markPaid($january->fresh(), '2026-02-28')->status);
        $this->assertSame(InvoiceStatus::Cancelled, $invoices->cancel($february->fresh())->status);

        // Still linked, and the schedule is unaffected.
        $this->assertSame($recurring->id, $january->fresh()->recurring_invoice_id);
        $this->assertSame('2026-03-31', $recurring->fresh()->next_occurrence_on->toDateString());
    }

    public function test_the_scheduler_covers_every_business_but_each_only_gets_its_own_invoices(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceRecurring = $this->recurringFor($alice, $this->customerFor($alice, ['name' => 'Alice Customer']));
        $bobRecurring = $this->recurringFor($bob, $this->customerFor($bob, ['name' => 'Bob Customer']));

        $this->runGeneration();

        $aliceInvoice = $this->businessOf($alice)->invoices()->sole();
        $bobInvoice = $this->businessOf($bob)->invoices()->sole();
        $this->assertSame([$aliceRecurring->id, 'Alice Customer'], [$aliceInvoice->recurring_invoice_id, $aliceInvoice->customer_name]);
        $this->assertSame([$bobRecurring->id, 'Bob Customer'], [$bobInvoice->recurring_invoice_id, $bobInvoice->customer_name]);
    }

    public function test_the_command_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'invoices:generate-recurring'));

        $this->assertNotNull($event);
        $this->assertSame('5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_generated_drafts_are_not_money_until_issued(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $this->runGeneration();

        // The template itself and its draft add nothing to the totals.
        $this->actingAs($user)->get(route('dashboard', ['period' => 'this_year']))
            ->assertOk()
            ->assertSee('1 draft invoice not yet issued');
        $this->actingAs($user)->get(route('reports.summary', ['period' => 'this_year']))->assertDontSee('RM 300.00');

        app(InvoiceService::class)->issue($recurring->invoices()->sole());
        $this->actingAs($user)->get(route('reports.summary', ['period' => 'this_year']))->assertSee('RM 300.00');
    }
}
