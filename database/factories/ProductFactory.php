<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ProductType::Product,
            'name' => ucfirst(fake()->words(3, true)),
            'sku' => fake()->unique()->numerify('SKU-######'),
            'description' => fake()->optional()->sentence(),
            'unit' => 'piece',
            'selling_price' => sprintf('%d.%02d', fake()->numberBetween(1, 5000), fake()->numberBetween(0, 99)),
            'cost_price' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the item is a service.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Service,
            'unit' => 'hour',
        ]);
    }

    /**
     * Indicate that the item is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
