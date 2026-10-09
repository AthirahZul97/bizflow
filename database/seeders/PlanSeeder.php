<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Development paid plans so the plan comparison and billing:assign have something to work
 * with. The prices and limits here are PLACEHOLDERS for development: they are NOT approved
 * commercial pricing and must be replaced (as new plan versions) before anything is sold.
 *
 * Idempotent, and it never changes an existing plan version: plans are immutable once
 * referenced, so a (code, version) that exists is left alone.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $entitlements = [
            'customers.max' => 1000,
            'products.max' => 1000,
            'invoices.monthly_max' => 500,
            'recurring_invoices.max' => 50,
            'team.seats' => 1,
            'invoices.email' => true,
            // PLACEHOLDER development value, not an approved commercial limit.
            'expenses.ocr_monthly_max' => 100,
        ];

        $plans = [
            [
                'code' => 'dev-paid-monthly',
                'name' => 'Paid (monthly) — placeholder',
                'description' => 'PLACEHOLDER development plan and price. Not final commercial pricing.',
                'price' => '29.00',
                'billing_interval' => BillingInterval::Month,
                'sort_order' => 30,
            ],
            [
                'code' => 'dev-paid-yearly',
                'name' => 'Paid (yearly) — placeholder',
                'description' => 'PLACEHOLDER development plan and price. Not final commercial pricing.',
                'price' => '290.00',
                'billing_interval' => BillingInterval::Year,
                'sort_order' => 40,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->firstOrCreate(
                ['code' => $plan['code'], 'version' => 1],
                $plan + ['currency' => 'MYR', 'entitlements' => $entitlements, 'is_active' => true],
            );
        }
    }
}
