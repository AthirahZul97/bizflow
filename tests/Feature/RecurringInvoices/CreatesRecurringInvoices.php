<?php

namespace Tests\Feature\RecurringInvoices;

use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\RecurringInvoiceService;
use Illuminate\Support\Facades\Artisan;

/**
 * Shared helpers for recurring invoice tests. "Today" is fixed at 2026-01-31 in
 * the application's Asia/Kuala_Lumpur timezone, a month end, so the month-end
 * rules are exercised by default.
 */
trait CreatesRecurringInvoices
{
    protected function setUpCreatesRecurringInvoices(): void
    {
        $this->travelTo('2026-01-31 10:00:00');
    }

    protected function customerFor(User $user, array $attributes = []): Customer
    {
        return Customer::factory()->ownedBy($user)->create($attributes);
    }

    /**
     * A valid create/update payload: monthly from today, one manual line of RM 300.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    protected function recurringPayload(Customer $customer, ?array $items = null, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Monthly website maintenance',
            'customer_id' => $customer->id,
            'frequency' => 'monthly',
            'start_date' => today()->toDateString(),
            'end_date' => null,
            'payment_terms_days' => '14',
            'discount_amount' => '0',
            'tax_label' => null,
            'tax_rate' => '0',
            'notes' => null,
            'items' => $items ?? [[
                'product_id' => null,
                'name' => 'Website maintenance',
                'description' => 'Monthly maintenance',
                'unit' => 'month',
                'quantity' => '1',
                'unit_price' => '300.00',
            ]],
        ], $overrides);
    }

    /**
     * Save a recurring invoice through the real service.
     *
     * @param  list<array<string, mixed>>|null  $items
     */
    protected function recurringFor(User $user, ?Customer $customer = null, ?array $items = null, array $overrides = []): RecurringInvoice
    {
        $customer ??= $this->customerFor($user);

        return app(RecurringInvoiceService::class)
            ->save($this->businessOf($user), $user, $this->recurringPayload($customer, $items, $overrides));
    }

    /**
     * Run the scheduler's command, as the hourly schedule does.
     */
    protected function runGeneration(): string
    {
        Artisan::call('invoices:generate-recurring');

        return Artisan::output();
    }
}
