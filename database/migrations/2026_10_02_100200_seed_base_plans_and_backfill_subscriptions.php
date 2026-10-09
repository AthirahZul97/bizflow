<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seeds the three plans a production install needs to work and gives every existing
     * business one current subscription on the Legacy plan: no expiry, no limits, no payment,
     * no trial. Existing behaviour continues unchanged.
     *
     * Idempotent: a plan is only inserted when its (code, version) is missing, and only
     * businesses without any subscription row get one, so re-running creates nothing twice.
     * It touches no existing business data. The entitlement keys are written out literally on
     * purpose: a migration must not change meaning when application code later changes.
     *
     * The Free and Trial limits are PLACEHOLDER development values, not approved commercial
     * policy. Paid plans are not seeded here (see Database\Seeders\PlanSeeder).
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->plans() as $plan) {
            $exists = DB::table('plans')->where('code', $plan['code'])->where('version', 1)->exists();

            if (! $exists) {
                DB::table('plans')->insert($plan + ['version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $legacyId = DB::table('plans')->where('code', 'legacy')->where('version', 1)->value('id');

        DB::table('businesses')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('subscriptions')
                ->whereColumn('subscriptions.business_id', 'businesses.id'))
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(100, function ($businesses) use ($legacyId, $now) {
                $rows = [];

                foreach ($businesses as $business) {
                    $rows[] = [
                        'business_id' => $business->id,
                        'plan_id' => $legacyId,
                        'status' => 'active',
                        'is_current' => 1,
                        'started_at' => $business->created_at ?? $now,
                        'cancel_at_period_end' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // The unique (business_id, is_current) key is the final guard against a duplicate.
                DB::table('subscriptions')->insertOrIgnore($rows);
            });
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: dropping the subscriptions and plans tables (the migrations that
     * created them) removes the seeded rows.
     */
    public function down(): void
    {
        //
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'code' => 'legacy',
                'name' => 'Legacy',
                'description' => 'Grandfathered plan for businesses that existed before subscriptions: no expiry, no limits.',
                'currency' => 'MYR',
                'price' => '0.00',
                'billing_interval' => null,
                'trial_days' => null,
                'entitlements' => json_encode([
                    'customers.max' => null,
                    'products.max' => null,
                    'invoices.monthly_max' => null,
                    'recurring_invoices.max' => null,
                    'team.seats' => null,
                    'invoices.email' => true,
                ]),
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'code' => 'trial',
                'name' => 'Trial',
                'description' => 'A one-time 14-day trial with generous development limits (placeholder, not final).',
                'currency' => 'MYR',
                'price' => '0.00',
                'billing_interval' => null,
                'trial_days' => 14,
                'entitlements' => json_encode([
                    'customers.max' => 200,
                    'products.max' => 200,
                    'invoices.monthly_max' => 100,
                    'recurring_invoices.max' => 10,
                    'team.seats' => 1,
                    'invoices.email' => true,
                ]),
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'free',
                'name' => 'Free',
                'description' => 'A free plan with small development limits (placeholder, not final).',
                'currency' => 'MYR',
                'price' => '0.00',
                'billing_interval' => null,
                'trial_days' => null,
                'entitlements' => json_encode([
                    'customers.max' => 10,
                    'products.max' => 10,
                    'invoices.monthly_max' => 5,
                    'recurring_invoices.max' => 0,
                    'team.seats' => 1,
                    'invoices.email' => false,
                ]),
                'is_active' => true,
                'sort_order' => 20,
            ],
        ];
    }
};
