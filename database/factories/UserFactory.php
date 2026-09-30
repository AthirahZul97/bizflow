<?php

namespace Database\Factories;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use WeakMap;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Users that must be created without a business (see withoutBusiness()).
     *
     * @var WeakMap<User, true>|null
     */
    private static ?WeakMap $withoutBusiness = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Give every user their own business (named after them) as its owner, like
     * registration does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (isset(self::withoutBusinessMap()[$user])) {
                return;
            }

            $business = Business::factory()->withoutOwner()->create(['name' => $user->name]);
            $business->members()->attach($user, ['role' => BusinessRole::Owner->value]);
        });
    }

    /**
     * Create the user without a business membership.
     */
    public function withoutBusiness(): static
    {
        return $this->afterMaking(function (User $user) {
            self::withoutBusinessMap()[$user] = true;
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * @return WeakMap<User, true>
     */
    private static function withoutBusinessMap(): WeakMap
    {
        return self::$withoutBusiness ??= new WeakMap;
    }
}
