<?php

namespace Database\Factories;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * A business with an owner, like one created at registration.
 *
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Businesses that must be created without an owner (see withoutOwner()).
     *
     * @var WeakMap<Business, true>|null
     */
    private static ?WeakMap $withoutOwner = null;

    /**
     * Define the model's default state. The profile is left empty, like a new account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * Give every business an owner, keeping the one-owner rule true in tests.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Business $business) {
            if (isset(self::withoutOwnerMap()[$business])) {
                return;
            }

            $owner = User::factory()->withoutBusiness()->create();
            $business->members()->attach($owner, ['role' => BusinessRole::Owner->value]);
        });
    }

    /**
     * Create the business without an owner, for callers that attach one themselves.
     */
    public function withoutOwner(): static
    {
        return $this->afterMaking(function (Business $business) {
            self::withoutOwnerMap()[$business] = true;
        });
    }

    /**
     * A fully filled-in profile.
     */
    public function withProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'registration_number' => '202001234567 (1234567-A)',
            'sst_number' => 'W10-1808-32000012',
            'email' => 'billing@example.test',
            'phone' => '+60 3-1234 5678',
            'address_line_1' => '12 Jalan Bukit',
            'address_line_2' => 'Taman Melawati',
            'city' => 'Kuala Lumpur',
            'state' => 'Wilayah Persekutuan',
            'postcode' => '53100',
            'country' => 'Malaysia',
        ]);
    }

    /**
     * @return WeakMap<Business, true>
     */
    private static function withoutOwnerMap(): WeakMap
    {
        return self::$withoutOwner ??= new WeakMap;
    }
}
