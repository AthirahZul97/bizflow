<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Models\Business;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'expense_date' => fake()->dateTimeBetween('2026-01-01', '2026-09-01')->format('Y-m-d'),
            'category' => fake()->randomElement(ExpenseCategory::cases()),
            'description' => ucfirst(fake()->words(3, true)),
            'amount' => sprintf('%d.%02d', fake()->numberBetween(1, 5000), fake()->numberBetween(0, 99)),
            'payee' => fake()->optional()->company(),
            'notes' => null,
        ];
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
