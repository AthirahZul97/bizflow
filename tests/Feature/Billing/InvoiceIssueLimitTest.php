<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementService;
use App\Enums\DenyReason;
use App\Enums\Entitlement;
use App\Enums\InvoiceStatus;
use App\Exceptions\EntitlementException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Invoices\CreatesInvoices;
use Tests\TestCase;

class InvoiceIssueLimitTest extends TestCase
{
    use CreatesInvoices;
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 10:00:00');
        $this->owner = User::factory()->create();
    }

    private function issuedBefore(User $user, string $when): Invoice
    {
        $invoice = $this->issuedFor($user);
        $invoice->forceFill(['issued_at' => $when])->save();

        return $invoice;
    }

    public function test_an_invoice_can_be_issued_below_the_monthly_limit(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 2]);
        $this->issuedFor($this->owner);

        $second = $this->issuedFor($this->owner);

        $this->assertSame(InvoiceStatus::Issued, $second->status);
        $this->assertSame('INV-00002', $second->invoice_number);
    }

    public function test_issuing_at_the_monthly_limit_is_refused_and_the_invoice_stays_a_draft(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $this->issuedFor($this->owner);
        $draft = $this->draftFor($this->owner);

        try {
            app(InvoiceService::class)->issue($draft);
            $this->fail('Expected the issue to be refused');
        } catch (EntitlementException $e) {
            $this->assertSame(DenyReason::LimitReached, $e->check->reason);
            $this->assertStringContainsString('1 invoices a month', $e->getMessage());
        }

        $draft->refresh();
        $this->assertSame(InvoiceStatus::Draft, $draft->status);
        $this->assertNull($draft->invoice_number);
        $this->assertNull($draft->issued_at);
    }

    public function test_a_refused_issue_consumes_no_invoice_number(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $this->issuedFor($this->owner);
        $draft = $this->draftFor($this->owner);
        $this->assertThrows(fn () => app(InvoiceService::class)->issue($draft), EntitlementException::class);

        $this->limitTo($this->owner, ['invoices.monthly_max' => 5]);
        $issued = app(InvoiceService::class)->issue($draft->fresh());

        $this->assertSame('INV-00002', $issued->invoice_number);
        $this->assertSame([1, 2], $this->businessOf($this->owner)->invoices()->orderBy('invoice_sequence')->whereNotNull('invoice_sequence')->pluck('invoice_sequence')->all());
    }

    public function test_drafts_never_count_and_are_not_limited(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);

        foreach (range(1, 4) as $i) {
            $this->draftFor($this->owner);
        }

        $this->assertSame(4, $this->businessOf($this->owner)->invoices()->count());
        $this->assertSame(0, app(EntitlementService::class)->fresh($this->businessOf($this->owner))->used(Entitlement::InvoicesPerMonth));
        $this->assertSame(InvoiceStatus::Issued, $this->issuedFor($this->owner)->status);
    }

    public function test_cancelled_invoices_still_count_towards_the_limit(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        app(InvoiceService::class)->cancel($this->issuedFor($this->owner));

        $this->assertThrows(fn () => $this->issuedFor($this->owner), EntitlementException::class);
    }

    public function test_paying_an_invoice_does_not_free_the_slot(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        app(InvoiceService::class)->markPaid($this->issuedFor($this->owner), '2026-10-15');

        $this->assertThrows(fn () => $this->issuedFor($this->owner), EntitlementException::class);
    }

    public function test_the_limit_resets_with_the_calendar_month_in_kuala_lumpur(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $this->issuedBefore($this->owner, '2026-10-31 23:59:59');
        $this->travelTo('2026-10-31 23:59:59');
        $this->assertThrows(fn () => $this->issuedFor($this->owner), EntitlementException::class);

        $this->travelTo('2026-11-01 00:00:00');
        $this->assertSame(InvoiceStatus::Issued, $this->issuedFor($this->owner)->status);
    }

    public function test_the_issue_date_the_user_picks_cannot_dodge_the_limit(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $this->issuedFor($this->owner, overrides: ['issue_date' => '2024-01-01', 'due_date' => '2024-02-01']);

        $this->assertThrows(
            fn () => $this->issuedFor($this->owner, overrides: ['issue_date' => '2024-01-01', 'due_date' => '2024-02-01']),
            EntitlementException::class,
        );
    }

    public function test_another_businesss_invoices_do_not_use_up_the_limit(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $this->issuedFor(User::factory()->create());
        $this->issuedFor(User::factory()->create());

        $this->assertSame(InvoiceStatus::Issued, $this->issuedFor($this->owner)->status);
    }

    public function test_a_plan_without_the_entitlement_cannot_issue(): void
    {
        $plan = $this->planWith(['customers.max' => 5]);
        $this->subscribe($this->owner, fn ($f) => $f->state(['plan_id' => $plan->getKey()]));

        $this->assertThrows(fn () => $this->issuedFor($this->owner), EntitlementException::class, 'include');
    }

    public function test_the_service_refuses_to_issue_in_read_only_even_if_the_http_layer_is_bypassed(): void
    {
        $draft = $this->draftFor($this->owner);
        $this->makeReadOnly($this->owner);

        $this->assertThrows(fn () => app(InvoiceService::class)->issue($draft), EntitlementException::class, 'read-only');
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
    }

    public function test_the_limit_is_checked_from_the_database_not_a_stale_memo(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $draft = $this->draftFor($this->owner);
        $business = $this->businessOf($this->owner);
        $service = app(EntitlementService::class);
        $this->assertTrue($service->for($business)->canWrite());

        $this->makeReadOnly($this->owner);

        $this->assertThrows(fn () => app(InvoiceService::class)->issue($draft), EntitlementException::class);
    }

    public function test_grace_still_allows_issuing(): void
    {
        $this->lapsedPaid($this->owner, 3);

        $this->assertSame(InvoiceStatus::Issued, $this->issuedFor($this->owner)->status);
    }

    public function test_the_issue_button_posts_back_with_the_reason_at_the_limit(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 1]);
        $this->issuedFor($this->owner);
        $draft = $this->draftFor($this->owner);

        $this->actingAs($this->owner)->from(route('invoices.show', $draft))->post(route('invoices.issue', $draft))
            ->assertRedirect(route('invoices.show', $draft))
            ->assertSessionHas('error');

        $this->assertStringContainsString('limit of 1 invoices a month', session('error'));
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
    }

    public function test_a_legacy_business_is_never_limited(): void
    {
        foreach (range(1, 6) as $i) {
            $this->issuedFor($this->owner);
        }

        $this->assertSame(6, $this->businessOf($this->owner)->invoices()->where('status', InvoiceStatus::Issued->value)->count());
    }

    public function test_recurring_numbering_is_not_disturbed_by_the_check(): void
    {
        $this->limitTo($this->owner, ['invoices.monthly_max' => 3]);

        $numbers = [$this->issuedFor($this->owner)->invoice_number, $this->issuedFor($this->owner)->invoice_number];

        $this->assertSame(['INV-00001', 'INV-00002'], $numbers);
    }
}
