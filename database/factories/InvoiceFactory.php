<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Builds invoices directly for tests that need a record in a given state.
 * Real invoices are always written through InvoiceService.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state: an empty draft for a customer of the same business.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['business_id' => $attributes['business_id']])->id,
            'status' => InvoiceStatus::Draft,
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
            'currency_code' => 'MYR',
            'customer_name' => fn (array $attributes) => Customer::find($attributes['customer_id'])->name,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ];
    }

    /**
     * An issued invoice, numbered by the real generator.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ])->afterCreating(function (Invoice $invoice) {
            DB::transaction(function () use ($invoice) {
                app(InvoiceNumberGenerator::class)->assign($invoice);
                $invoice->save();
            });
        });
    }

    public function paid(): static
    {
        return $this->issued()->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => today()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->issued()->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
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
}
