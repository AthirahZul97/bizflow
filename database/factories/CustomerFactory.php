<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
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
            'name' => fake()->name(),
            'company_name' => fake()->optional()->company(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->numerify('+60 1#-### ####'),
            'address_line_1' => fake()->optional()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->optional()->city(),
            'state' => null,
            'postcode' => fake()->optional()->numerify('#####'),
            'country' => fake()->optional()->country(),
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
