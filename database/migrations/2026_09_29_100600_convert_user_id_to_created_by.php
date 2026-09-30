<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stage 6 of the tenancy migration. user_id is no longer an ownership key:
     *
     * - invoices and expenses keep it as created_by, audit metadata recording the
     *   person who created the record. NULL means system-generated or no associated
     *   person. Deleting that user sets it to NULL rather than deleting the record.
     * - customers and products drop it.
     *
     * created_by must never be used for authorization or tenant filtering; ownership
     * is business_id alone.
     */
    public function up(): void
    {
        foreach (['invoices', 'expenses'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->renameColumn('user_id', 'created_by'));
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->change();
            });
            Schema::table($name, function (Blueprint $table) {
                $table->index('created_by');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        foreach (['customers', 'products'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('user_id'));
        }
    }

    /**
     * Reverse the migrations.
     *
     * Not reversible automatically: customers and products no longer record a
     * user, and records created after this point may have no creator. Recover by
     * restoring the backup taken before migrating.
     */
    public function down(): void
    {
        throw new RuntimeException('The created_by migration cannot be rolled back. Restore the pre-migration database backup.');
    }
};
