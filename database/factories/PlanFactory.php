<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds extra plan versions for tests. The Legacy, Trial and Free plans already exist
 * (the migration seeds them); fetch those with seeded().
 *
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state: a free plan with a unique code and the Free-like limits.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'test-'.fake()->unique()->bothify('????##'),
            'version' => 1,
            'name' => fake()->words(2, true),
            'currency' => 'MYR',
            'price' => '0.00',
            'billing_interval' => null,
            'entitlements' => [
                'customers.max' => 10,
                'products.max' => 10,
                'invoices.monthly_max' => 5,
                'recurring_invoices.max' => 0,
                'team.seats' => 1,
                'invoices.email' => false,
            ],
            'is_active' => true,
            'sort_order' => 100,
        ];
    }

    /**
     * A paid plan billed monthly. Test value only, not a real price.
     */
    public function monthly(string $price = '10.00'): static
    {
        return $this->state(['price' => $price, 'billing_interval' => BillingInterval::Month]);
    }

    /**
     * A paid plan billed yearly. Test value only, not a real price.
     */
    public function yearly(string $price = '100.00'): static
    {
        return $this->state(['price' => $price, 'billing_interval' => BillingInterval::Year]);
    }

    /**
     * Replace the entitlements.
     *
     * @param  array<string, int|bool|null>  $entitlements
     */
    public function entitlements(array $entitlements): static
    {
        return $this->state(['entitlements' => $entitlements]);
    }

    /**
     * Every limit unlimited and email allowed.
     */
    public function unlimited(): static
    {
        return $this->entitlements([
            'customers.max' => null,
            'products.max' => null,
            'invoices.monthly_max' => null,
            'recurring_invoices.max' => null,
            'team.seats' => null,
            'invoices.email' => true,
            'expenses.ocr_monthly_max' => null,
        ]);
    }

    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * One of the plans the migration seeded (legacy, trial or free), version 1.
     */
    public static function seeded(string $code): Plan
    {
        return Plan::query()->where('code', config("billing.plans.{$code}"))->where('version', 1)->firstOrFail();
    }
}
