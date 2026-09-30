<?php

namespace Database\Factories;

use App\Enums\InvoiceEmailStatus;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds send-history rows directly for tests that need one in a given state.
 * Real rows are always written through InvoiceEmailService.
 *
 * @extends Factory<InvoiceEmail>
 */
class InvoiceEmailFactory extends Factory
{
    /**
     * Define the model's default state: a queued send to the invoice's address.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'requested_by' => null,
            'recipient_email' => fake()->safeEmail(),
            'recipient_source' => InvoiceEmail::SOURCE_INVOICE,
            'subject' => 'Invoice',
            'status' => InvoiceEmailStatus::Queued,
            'attempts' => 0,
            'queued_at' => now(),
        ];
    }

    public function status(InvoiceEmailStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
