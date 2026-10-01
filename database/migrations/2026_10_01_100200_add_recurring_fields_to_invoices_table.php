<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links a generated invoice to its recurring invoice and to the occurrence it
     * was generated for. Both columns are NULL on ordinary invoices.
     *
     * - The unique (recurring_invoice_id, recurring_occurrence_on) key is what makes
     *   one invoice per occurrence a database guarantee. Rows where the columns are
     *   NULL never conflict, so ordinary invoices are unaffected.
     * - The composite foreign key means an invoice can only link to a recurring
     *   invoice of its own business. It is not checked while recurring_invoice_id
     *   is NULL, and it restricts deleting a recurring invoice that has invoices.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('recurring_invoice_id')->nullable()->after('customer_id');
            $table->date('recurring_occurrence_on')->nullable()->after('recurring_invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Created before the foreign key so MySQL reuses it instead of adding a separate index.
            $table->index(['business_id', 'recurring_invoice_id']);
            $table->unique(['recurring_invoice_id', 'recurring_occurrence_on']);
            $table->foreign(['business_id', 'recurring_invoice_id'])
                ->references(['business_id', 'id'])->on('recurring_invoices')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * For development only: this permanently loses the link between generated
     * invoices and their recurring invoice. Production recovery is restoring the
     * backup taken before migrating.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['business_id', 'recurring_invoice_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['recurring_invoice_id', 'recurring_occurrence_on']);
            $table->dropIndex(['business_id', 'recurring_invoice_id']);
            $table->dropColumn(['recurring_invoice_id', 'recurring_occurrence_on']);
        });
    }
};
