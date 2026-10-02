<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plans are platform catalogue data, not business data, so they have no business_id.
     * A plan row is an immutable version: changing its commercial terms (price, interval,
     * trial length, entitlements) means inserting a new row with the same code and a higher
     * version. Rows that subscriptions reference are never deleted; retire them with is_active.
     *
     * entitlements is a JSON map keyed by App\Enums\Entitlement values. An integer is a limit,
     * null is unlimited, 0 is "not included", a boolean is a flag and an absent key is denied.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->char('currency', 3)->default('MYR');
            $table->decimal('price', 10, 2)->default(0);
            // NULL means the plan has no billing period (free, trial or grandfathered).
            $table->string('billing_interval', 10)->nullable();
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->json('entitlements');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * For development only. Production recovery is restoring the backup taken before migrating.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
