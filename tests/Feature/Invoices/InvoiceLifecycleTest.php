<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceStateException;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use App\Services\InvoiceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    public function test_issuing_assigns_sequential_numbers_and_freezes_the_invoice(): void
    {
        $user = User::factory()->create();
        $first = $this->draftFor($user);
        $second = $this->draftFor($user);

        $this->actingAs($user)->post(route('invoices.issue', $first))
            ->assertRedirect(route('invoices.show', $first))
            ->assertSessionHas('status', 'Invoice INV-00001 issued.');
        $this->actingAs($user)->post(route('invoices.issue', $second));

        $first->refresh();
        $this->assertSame(InvoiceStatus::Issued, $first->status);
        $this->assertSame('INV-00001', $first->invoice_number);
        $this->assertSame(1, $first->invoice_sequence);
        $this->assertNotNull($first->issued_at);
        $this->assertSame('INV-00002', $second->fresh()->invoice_number);
    }

    public function test_deleted_drafts_do_not_use_up_numbers(): void
    {
        $user = User::factory()->create();
        $this->draftFor($user);
        $keep = $this->draftFor($user);
        $discard = $this->draftFor($user);

        $this->actingAs($user)->delete(route('invoices.destroy', $discard));
        $this->actingAs($user)->post(route('invoices.issue', $keep));
        $this->assertSame('INV-00001', $keep->fresh()->invoice_number);

        $later = $this->draftFor($user);
        $this->actingAs($user)->post(route('invoices.issue', $later));
        $this->assertSame('INV-00002', $later->fresh()->invoice_number);
    }

    public function test_each_user_has_an_independent_sequence(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->assertSame('INV-00001', $this->issuedFor($alice)->invoice_number);
        $this->assertSame('INV-00002', $this->issuedFor($alice)->invoice_number);
        $this->assertSame('INV-00001', $this->issuedFor($bob)->invoice_number);
    }

    public function test_issuing_copies_the_customers_latest_details(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Draft Time Name', 'city' => 'Ipoh']);
        $invoice = $this->draftFor($user, $customer);
        $customer->update(['name' => 'Issue Time Name', 'city' => 'Penang']);

        $this->actingAs($user)->post(route('invoices.issue', $invoice));

        $invoice->refresh();
        $this->assertSame('Issue Time Name', $invoice->customer_name);
        $this->assertSame('Penang', $invoice->customer_city);
    }

    public function test_issued_invoices_do_not_change_when_customer_or_product_change(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Original Customer']);
        $product = Product::factory()->for($user)->create(['name' => 'Original Product', 'selling_price' => '100.00']);
        $invoice = app(InvoiceService::class)->issue(
            $this->draftFor($user, $customer, [['product_id' => $product->id, 'quantity' => '1']])
        );

        $customer->update(['name' => 'Renamed Customer']);
        $product->update(['name' => 'Renamed Product', 'selling_price' => '999.00']);

        $invoice->refresh();
        $this->assertSame('Original Customer', $invoice->customer_name);
        $this->assertSame('Original Product', $invoice->items->sole()->name);
        $this->assertSame('100.00', $invoice->total);

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('Original Customer')
            ->assertDontSee('Renamed Customer')
            ->assertDontSee('Renamed Product');
    }

    public function test_the_number_cannot_be_set_through_the_request(): void
    {
        $user = User::factory()->create();
        $invoice = $this->draftFor($user);

        $this->actingAs($user)->post(route('invoices.issue', $invoice), [
            'invoice_number' => 'INV-99999',
            'invoice_sequence' => 99999,
            'status' => 'paid',
        ]);

        $invoice->refresh();
        $this->assertSame('INV-00001', $invoice->invoice_number);
        $this->assertSame(1, $invoice->invoice_sequence);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
    }

    public function test_an_invoice_without_lines_cannot_be_issued(): void
    {
        $draft = Invoice::factory()->create();

        $this->actingAs($draft->user)
            ->from(route('invoices.show', $draft))
            ->post(route('invoices.issue', $draft))
            ->assertRedirect(route('invoices.show', $draft))
            ->assertSessionHas('error', 'An invoice needs at least one line before it can be issued.');

        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->invoice_number);
    }

    public function test_a_stale_request_cannot_issue_an_invoice_twice(): void
    {
        $user = User::factory()->create();
        $stale = $this->draftFor($user);
        app(InvoiceService::class)->issue($stale);

        // $stale still says "draft" in memory; the service re-reads the locked row.
        $this->expectException(InvoiceStateException::class);

        try {
            app(InvoiceService::class)->issue($stale);
        } finally {
            $this->assertSame(1, Invoice::whereNotNull('invoice_number')->count());
            $this->assertSame('INV-00001', $stale->fresh()->invoice_number);
        }
    }

    public function test_issued_invoices_can_be_marked_paid_and_unpaid(): void
    {
        $user = User::factory()->create();
        $invoice = $this->issuedFor($user);

        $this->actingAs($user)->post(route('invoices.mark-paid', $invoice), ['paid_at' => '2026-09-20'])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status', 'Invoice INV-00001 marked as paid.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('2026-09-20', $invoice->paid_at->toDateString());

        $this->actingAs($user)->get(route('invoices.show', $invoice))->assertSee('Marked as paid on 20 Sep 2026');

        $this->actingAs($user)->post(route('invoices.mark-unpaid', $invoice))
            ->assertSessionHas('status', 'Invoice INV-00001 marked as unpaid.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame('INV-00001', $invoice->invoice_number);
    }

    public function test_payment_date_is_validated(): void
    {
        $user = User::factory()->create();
        $invoice = $this->issuedFor($user); // issued 2026-09-01; today is 2026-09-28

        foreach (['', 'yesterday', '28/09/2026', '2026-08-31', '2026-09-29'] as $paidAt) {
            $this->actingAs($user)->post(route('invoices.mark-paid', $invoice), ['paid_at' => $paidAt])
                ->assertSessionHasErrors('paid_at');
        }

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);

        foreach (['2026-09-01', '2026-09-28'] as $boundary) {
            $this->actingAs($user)->post(route('invoices.mark-paid', $invoice), ['paid_at' => $boundary])
                ->assertSessionHasNoErrors();
            app(InvoiceService::class)->markUnpaid($invoice);
        }
    }

    public function test_today_follows_the_malaysian_timezone(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', config('app.timezone'));

        // 20:00 UTC on 27 Sep is already 04:00 on 28 Sep in Kuala Lumpur.
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(20, 0));
        $user = User::factory()->create();
        $invoice = $this->issuedFor($user);

        $this->actingAs($user)->post(route('invoices.mark-paid', $invoice), ['paid_at' => '2026-09-28'])
            ->assertSessionHasNoErrors();
    }

    public function test_issued_invoices_can_be_cancelled_and_keep_their_number(): void
    {
        $user = User::factory()->create();
        $invoice = $this->issuedFor($user);

        $this->actingAs($user)->get(route('invoices.cancel.confirm', $invoice))
            ->assertOk()
            ->assertSee('Cancel invoice INV-00001');
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);

        $this->actingAs($user)->post(route('invoices.cancel', $invoice))
            ->assertSessionHas('status', 'Invoice INV-00001 cancelled.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame('INV-00001', $invoice->invoice_number);
        $this->assertNotNull($invoice->cancelled_at);

        // The next invoice does not reuse the cancelled number.
        $this->assertSame('INV-00002', $this->issuedFor($user)->invoice_number);
    }

    public function test_overdue_is_derived_from_the_due_date_and_never_stored(): void
    {
        $user = User::factory()->create();
        $overdue = $this->issuedFor($user, overrides: ['due_date' => '2026-09-27']);
        $dueToday = $this->issuedFor($user, overrides: ['due_date' => '2026-09-28']);
        $paidLate = $this->issuedFor($user, overrides: ['due_date' => '2026-09-10']);
        app(InvoiceService::class)->markPaid($paidLate, '2026-09-20');
        $draftPastDue = $this->draftFor($user, overrides: ['due_date' => '2026-09-10']);

        $this->assertTrue($overdue->fresh()->isOverdue());
        $this->assertSame('issued', $overdue->fresh()->getRawOriginal('status'));
        $this->assertFalse($dueToday->fresh()->isOverdue());
        $this->assertFalse($paidLate->fresh()->isOverdue());
        $this->assertFalse($draftPastDue->fresh()->isOverdue());

        $this->actingAs($user)->get(route('invoices.show', $overdue))->assertSee('Overdue');

        // A day later the invoice due today becomes overdue without any stored change.
        $this->travelTo('2026-09-29 10:00:00');
        $this->assertTrue($dueToday->fresh()->isOverdue());
        $this->assertSame('issued', $dueToday->fresh()->getRawOriginal('status'));
    }

    public function test_database_rejects_duplicate_numbers_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $this->issuedFor($user);
        $draft = $this->draftFor($user);

        $this->expectException(UniqueConstraintViolationException::class);

        $draft->forceFill(['invoice_number' => 'INV-00001', 'invoice_sequence' => 2])->save();
    }

    public function test_database_rejects_duplicate_sequences_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $this->issuedFor($user);
        $draft = $this->draftFor($user);

        $this->expectException(UniqueConstraintViolationException::class);

        $draft->forceFill(['invoice_number' => 'INV-X', 'invoice_sequence' => 1])->save();
    }

    public function test_number_format_comes_from_config(): void
    {
        config(['bizflow.invoice.number_prefix' => 'BF-', 'bizflow.invoice.number_padding' => 3]);

        $this->assertSame('BF-007', app(InvoiceNumberGenerator::class)->format(7));
        $this->assertSame('BF-1234', app(InvoiceNumberGenerator::class)->format(1234));
    }

    public function test_cancelled_and_paid_invoices_cannot_be_edited_or_deleted(): void
    {
        $user = User::factory()->create();
        $cancelled = app(InvoiceService::class)->cancel($this->issuedFor($user));
        $paid = app(InvoiceService::class)->markPaid($this->issuedFor($user), '2026-09-28');

        foreach ([$cancelled, $paid] as $invoice) {
            $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertForbidden();
            $this->actingAs($user)->delete(route('invoices.destroy', $invoice))->assertForbidden();
            $this->assertModelExists($invoice);
        }
    }

    public function test_show_page_offers_only_the_actions_allowed_for_the_status(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor($user);
        $issued = $this->issuedFor($user);
        $paid = app(InvoiceService::class)->markPaid($this->issuedFor($user), '2026-09-28');
        $cancelled = app(InvoiceService::class)->cancel($this->issuedFor($user));

        $this->actingAs($user)->get(route('invoices.show', $draft))
            ->assertSee(route('invoices.issue', $draft))
            ->assertSee(route('invoices.edit', $draft))
            ->assertDontSee(route('invoices.mark-paid', $draft));

        $this->actingAs($user)->get(route('invoices.show', $issued))
            ->assertSee(route('invoices.mark-paid', $issued))
            ->assertSee(route('invoices.cancel.confirm', $issued))
            ->assertDontSee(route('invoices.edit', $issued));

        $this->actingAs($user)->get(route('invoices.show', $paid))
            ->assertSee(route('invoices.mark-unpaid', $paid))
            ->assertDontSee(route('invoices.cancel.confirm', $paid));

        $this->actingAs($user)->get(route('invoices.show', $cancelled))
            ->assertSee('Cancelled')
            ->assertDontSee(route('invoices.mark-paid', $cancelled))
            ->assertDontSee(route('invoices.edit', $cancelled));
    }
}
