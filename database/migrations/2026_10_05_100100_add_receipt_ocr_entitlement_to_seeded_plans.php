<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The monthly receipt-OCR limit key. Written out literally on purpose: a migration must not
     * change meaning when application code later changes.
     */
    private const KEY = 'expenses.ocr_monthly_max';

    /**
     * Run the migrations.
     *
     * Adds the receipt OCR entitlement to the three seeded plans. The values are DEVELOPMENT
     * PLACEHOLDERS, not approved commercial terms: Legacy unlimited (null, matching its
     * grandfathered full-access nature), Trial 20 a month, Free 0 (not included).
     *
     * Plans are immutable once a subscription references them, so:
     * - a version nothing references yet is updated in place (the case on a fresh install and
     *   when the Phase 2D migration has only just run);
     * - a referenced version is left untouched, and a new version (v+1) with the key is
     *   inserted instead, the old one retired so new trials use the new one. Businesses already
     *   on the old version have no OCR until an operator moves them (billing:assign).
     *
     * Idempotent: a plan that already has the key is skipped.
     */
    public function up(): void
    {
        foreach (['legacy' => null, 'trial' => 20, 'free' => 0] as $code => $limit) {
            $plan = DB::table('plans')->where('code', $code)->orderByDesc('version')->first();

            if ($plan === null) {
                continue;
            }

            $entitlements = json_decode($plan->entitlements, true) ?? [];

            if (array_key_exists(self::KEY, $entitlements)) {
                continue;
            }

            $entitlements[self::KEY] = $limit;
            $referenced = DB::table('subscriptions')->where('plan_id', $plan->id)->orWhere('pending_plan_id', $plan->id)->exists();

            if (! $referenced) {
                DB::table('plans')->where('id', $plan->id)->update(['entitlements' => json_encode($entitlements), 'updated_at' => now()]);

                continue;
            }

            $next = (array) $plan;
            unset($next['id']);
            $next['version'] = $plan->version + 1;
            $next['entitlements'] = json_encode($entitlements);
            $next['created_at'] = $next['updated_at'] = now();
            DB::table('plans')->insert($next);
            DB::table('plans')->where('id', $plan->id)->update(['is_active' => false]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: an unknown entitlement key is ignored, and plan rows referenced by
     * subscriptions must not be edited. Production recovery is restoring the backup.
     */
    public function down(): void
    {
        //
    }
};
