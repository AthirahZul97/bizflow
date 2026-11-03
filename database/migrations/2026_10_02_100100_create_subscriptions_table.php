<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A business's subscription history. Exactly one row per business is current
     * (is_current = 1); every other row has is_current NULL. MySQL allows many NULLs in a
     * unique index, so UNIQUE (business_id, is_current) enforces "at most one current".
     *
     * Rows are never edited into a different plan: a change ends the old row as 'replaced' and
     * starts a new one. pending_plan_id records a downgrade that takes effect at
     * current_period_ends_at; the replacement row is only created when that moment has passed.
     *
     * created_by is audit metadata only: NULL means system-generated or an operator.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('plan_id');
            $table->foreignId('pending_plan_id')->nullable();
            $table->string('status', 20);
            $table->unsignedTinyInteger('is_current')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            // NULL means the subscription has no end (grandfathered and free plans).
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->unique(['business_id', 'is_current']);
            $table->index(['business_id', 'started_at']);
            $table->index('plan_id');
            $table->index('pending_plan_id');
            $table->index('created_by');

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->foreign('pending_plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * For development only. Production recovery is restoring the backup taken before migrating.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
