<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state: a manual one-unit line.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'product_id' => null,
            'name' => ucfirst(fake()->words(2, true)),
            'description' => null,
            'unit' => null,
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'line_total' => '100.00',
            'position' => fn (array $attributes) => InvoiceItem::where('invoice_id', $attributes['invoice_id'])->max('position') + 1,
        ];
    }
}
