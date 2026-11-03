<?php

namespace Tests\Feature\Billing;

use App\Billing\Meters\CustomerCountMeter;
use App\Billing\Meters\MonthlyIssuedInvoicesMeter;
use App\Billing\Meters\ProductCountMeter;
use App\Billing\Meters\RecurringScheduleMeter;
use App\Billing\Meters\TeamSeatMeter;
use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageMeterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    private function issued(User $user, string $issuedAt, array $attributes = []): Invoice
    {
        return Invoice::factory()->ownedBy($user)->issued()->create(['issued_at' => $issuedAt] + $attributes);
    }

    public function test_customers_are_counted_for_the_business_only(): void
    {
        Customer::factory()->count(3)->ownedBy($this->owner)->create();
        Customer::factory()->count(5)->ownedBy($this->other)->create();

        $this->assertSame(3, (new CustomerCountMeter)->used($this->businessOf($this->owner)));
        $this->assertSame(5, (new CustomerCountMeter)->used($this->businessOf($this->other)));
    }

    public function test_deleting_a_customer_frees_capacity(): void
    {
        $customers = Customer::factory()->count(2)->ownedBy($this->owner)->create();

        $customers->first()->delete();

        $this->assertSame(1, (new CustomerCountMeter)->used($this->businessOf($this->owner)));
    }

    public function test_inactive_products_still_count(): void
    {
        Product::factory()->ownedBy($this->owner)->create();
        Product::factory()->inactive()->ownedBy($this->owner)->create();
        Product::factory()->service()->inactive()->ownedBy($this->owner)->create();

        $this->assertSame(3, (new ProductCountMeter)->used($this->businessOf($this->owner)));
    }

    public function test_deactivating_a_product_does_not_free_capacity_but_deleting_does(): void
    {
        $product = Product::factory()->ownedBy($this->owner)->create();
        $meter = new ProductCountMeter;

        $product->update(['is_active' => false]);
        $this->assertSame(1, $meter->used($this->businessOf($this->owner)));

        $product->delete();
        $this->assertSame(0, $meter->used($this->businessOf($this->owner)));
    }

    public function test_products_are_counted_per_business(): void
    {
        Product::factory()->count(2)->ownedBy($this->owner)->create();
        Product::factory()->ownedBy($this->other)->create();

        $this->assertSame(2, (new ProductCountMeter)->used($this->businessOf($this->owner)));
    }

    public function test_recurring_schedules_count_active_and_paused_but_not_cancelled(): void
    {
        RecurringInvoice::factory()->ownedBy($this->owner)->create();
        RecurringInvoice::factory()->ownedBy($this->owner)->paused()->create();
        RecurringInvoice::factory()->ownedBy($this->owner)->cancelled()->create();
        RecurringInvoice::factory()->ownedBy($this->other)->create();

        $this->assertSame(2, (new RecurringScheduleMeter)->used($this->businessOf($this->owner)));
    }

    public function test_team_seats_count_memberships(): void
    {
        $business = $this->businessOf($this->owner);
        $this->assertSame(1, (new TeamSeatMeter)->used($business));

        $business->members()->attach(User::factory()->withoutBusiness()->create(), ['role' => BusinessRole::Owner->value]);

        $this->assertSame(2, (new TeamSeatMeter)->used($business));
        $this->assertSame(1, (new TeamSeatMeter)->used($this->businessOf($this->other)));
    }

    public function test_only_issued_invoices_count_towards_the_monthly_limit(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $meter = new MonthlyIssuedInvoicesMeter;

        Invoice::factory()->ownedBy($this->owner)->create();
        Invoice::factory()->ownedBy($this->owner)->create();
        $this->assertSame(0, $meter->used($this->businessOf($this->owner)), 'drafts do not count');

        $this->issued($this->owner, '2026-10-02 09:00:00');
        $this->issued($this->owner, '2026-10-03 09:00:00', ['status' => InvoiceStatus::Paid, 'paid_at' => '2026-10-04']);
        $this->assertSame(2, $meter->used($this->businessOf($this->owner)));
    }

    public function test_cancelled_invoices_still_count_because_their_number_was_consumed(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $this->issued($this->owner, '2026-10-02 09:00:00');
        $this->issued($this->owner, '2026-10-03 09:00:00', ['status' => InvoiceStatus::Cancelled, 'cancelled_at' => '2026-10-04 09:00:00']);

        $this->assertSame(2, (new MonthlyIssuedInvoicesMeter)->used($this->businessOf($this->owner)));
    }

    public function test_the_system_issue_timestamp_decides_not_the_editable_issue_date(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $business = $this->businessOf($this->owner);

        // Issued this month, but back-dated to last year by the user.
        $this->issued($this->owner, '2026-10-05 09:00:00', ['issue_date' => '2025-01-01']);
        // Issued in September, but dated this month by the user.
        $this->issued($this->owner, '2026-09-05 09:00:00', ['issue_date' => '2026-10-10']);

        $this->assertSame(1, (new MonthlyIssuedInvoicesMeter)->used($business));
    }

    public function test_an_issued_invoice_without_a_timestamp_is_never_counted_by_accident(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $invoice = $this->issued($this->owner, '2026-10-05 09:00:00');
        $invoice->forceFill(['issued_at' => null])->save();

        $this->assertSame(0, (new MonthlyIssuedInvoicesMeter)->used($this->businessOf($this->owner)));
    }

    public function test_the_month_is_the_calendar_month_in_kuala_lumpur(): void
    {
        $business = $this->businessOf($this->owner);
        // 2026-09-30 23:59:59 and 2026-10-01 00:00:00 in Asia/Kuala_Lumpur (the stored timezone).
        $this->issued($this->owner, '2026-09-30 23:59:59');
        $this->issued($this->owner, '2026-10-01 00:00:00');
        $this->issued($this->owner, '2026-10-31 23:59:59');
        $this->issued($this->owner, '2026-11-01 00:00:00');
        $meter = new MonthlyIssuedInvoicesMeter;

        $this->travelTo('2026-10-20 10:00:00');
        $this->assertSame(2, $meter->used($business), 'October holds Oct 1 00:00:00 to Oct 31 23:59:59');

        $this->travelTo('2026-09-10 10:00:00');
        $this->assertSame(1, $meter->used($business));

        $this->travelTo('2026-11-02 10:00:00');
        $this->assertSame(1, $meter->used($business));
    }

    public function test_the_boundary_uses_kuala_lumpur_even_when_the_clock_reads_utc(): void
    {
        // 2026-09-30 17:00 UTC is 2026-10-01 01:00 in Kuala Lumpur: already October.
        [$start, $end] = MonthlyIssuedInvoicesMeter::currentMonth(CarbonImmutable::parse('2026-09-30 17:00:00', 'UTC'));

        $this->assertSame('2026-10-01 00:00:00', $start->setTimezone('Asia/Kuala_Lumpur')->toDateTimeString());
        $this->assertSame('2026-11-01 00:00:00', $end->setTimezone('Asia/Kuala_Lumpur')->toDateTimeString());
    }

    public function test_the_month_bounds_are_expressed_in_the_storage_timezone(): void
    {
        [$start] = MonthlyIssuedInvoicesMeter::currentMonth(CarbonImmutable::parse('2026-10-15 12:00:00'));

        $this->assertSame(config('app.timezone'), $start->getTimezone()->getName());
    }

    public function test_another_businesss_invoices_never_count(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $this->issued($this->other, '2026-10-02 09:00:00');
        $this->issued($this->other, '2026-10-03 09:00:00');
        $this->issued($this->owner, '2026-10-04 09:00:00');

        $this->assertSame(1, (new MonthlyIssuedInvoicesMeter)->used($this->businessOf($this->owner)));
    }

    public function test_recurring_generated_drafts_do_not_count_until_issued(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $recurring = RecurringInvoice::factory()->ownedBy($this->owner)->create();
        $draft = Invoice::factory()->ownedBy($this->owner)->create([
            'recurring_invoice_id' => $recurring->getKey(),
            'recurring_occurrence_on' => '2026-10-15',
        ]);
        $meter = new MonthlyIssuedInvoicesMeter;

        $this->assertSame(0, $meter->used($this->businessOf($this->owner)));

        $draft->forceFill(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'invoice_number' => 'INV-1', 'invoice_sequence' => 1])->save();
        $this->assertSame(1, $meter->used($this->businessOf($this->owner)));
    }
}
