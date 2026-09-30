<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables whose rows belong to a business.
     */
    private const TABLES = ['customers', 'products', 'invoices', 'expenses'];

    /**
     * Run the migrations.
     *
     * Stage 2 of the tenancy migration: add business_id, nullable until it has been
     * backfilled and verified. Indexes and foreign keys are added once it is required.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('business_id')->nullable()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Safe only while business_id is still the nullable, unindexed column added
     * here, i.e. before the "enforce business ownership" migration has run.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('business_id');
            });
        }
    }
};
