<?php

namespace Database\Factories;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringInvoiceStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds recurring invoices directly for tests that need one in a given state.
 * Real ones are always written through RecurringInvoiceService.
 *
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    /**
     * Define the model's default state: an active monthly schedule starting today,
     * for a customer of the same business, with one line (added after creating).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['business_id' => $attributes['business_id']])->id,
            'name' => 'Monthly retainer',
            'status' => RecurringInvoiceStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'start_date' => today()->toDateString(),
            'next_occurrence_on' => fn (array $attributes) => $attributes['start_date'],
            'payment_terms_days' => 30,
            'discount_amount' => '0.00',
            'tax_rate' => '0.00',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurring) {
            if ($recurring->items()->doesntExist()) {
                $recurring->items()->make([
                    'name' => 'Retainer', 'quantity' => '1', 'unit_price' => '300.00',
                ])->forceFill(['position' => 1])->save();
            }
        });
    }

    /**
     * Owned by the given user's business.
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'business_id' => $user->businesses()->sole()->getKey(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecurringInvoiceStatus::Paused,
            'paused_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecurringInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
