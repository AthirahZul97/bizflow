<?php

namespace Tests\Feature\Invoices;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;

/**
 * Shared helpers for invoice feature tests. "Today" is fixed at 2026-09-28 in
 * the application's Asia/Kuala_Lumpur timezone.
 */
trait CreatesInvoices
{
    protected function setUpCreatesInvoices(): void
    {
        $this->travelTo('2026-09-28 10:00:00');
    }

    protected function customerFor(User $user, array $attributes = []): Customer
    {
        return Customer::factory()->for($user)->create($attributes);
    }

    /**
     * A valid create/update payload with one manual line.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    protected function invoicePayload(Customer $customer, ?array $items = null, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'discount_amount' => '0',
            'tax_label' => null,
            'tax_rate' => '0',
            'notes' => null,
            'items' => $items ?? [[
                'product_id' => null,
                'name' => 'Website Maintenance',
                'description' => 'Monthly maintenance',
                'unit' => 'month',
                'quantity' => '1',
                'unit_price' => '300.00',
            ]],
        ], $overrides);
    }

    /**
     * Save a draft through the real service.
     *
     * @param  list<array<string, mixed>>|null  $items
     */
    protected function draftFor(User $user, ?Customer $customer = null, ?array $items = null, array $overrides = []): Invoice
    {
        $customer ??= $this->customerFor($user);

        return app(InvoiceService::class)->saveDraft($user, $this->invoicePayload($customer, $items, $overrides));
    }

    protected function issuedFor(User $user, ?Customer $customer = null, array $overrides = []): Invoice
    {
        return app(InvoiceService::class)->issue($this->draftFor($user, $customer, overrides: $overrides));
    }
}
