<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stage 3-4 of the tenancy migration: make business_id required and move every
     * index and foreign key from user_id to business_id.
     *
     * Order matters on MySQL: an index a foreign key relies on cannot be dropped, so
     * each table first gets its business_id indexes (created before their foreign
     * keys so MySQL reuses them), then its user_id foreign keys are dropped, and
     * only then the user_id indexes. customers runs before invoices because the
     * invoice -> customer foreign key references customers (business_id, id).
     */
    public function up(): void
    {
        $this->requireBusinessId('customers');
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['business_id', 'name']);
            // Lets invoices reference (business_id, id), so an invoice can only use its own business's customers.
            $table->unique(['business_id', 'id']);
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex(['user_id', 'name']));

        $this->requireBusinessId('products');
        Schema::table('products', function (Blueprint $table) {
            $table->index(['business_id', 'name']);
            $table->unique(['business_id', 'sku']);
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'name']);
            $table->dropUnique(['user_id', 'sku']);
        });

        $this->requireBusinessId('invoices');
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['business_id', 'invoice_number']);
            $table->unique(['business_id', 'invoice_sequence']);
            $table->index(['business_id', 'status', 'due_date']);
            $table->index(['business_id', 'issue_date']);
            $table->index(['business_id', 'customer_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['business_id', 'customer_id'])
                ->references(['business_id', 'id'])->on('customers')->restrictOnDelete();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['user_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropUnique(['user_id', 'invoice_number']);
            $table->dropUnique(['user_id', 'invoice_sequence']);
            $table->dropIndex(['user_id', 'status', 'due_date']);
            $table->dropIndex(['user_id', 'issue_date']);
        });

        $this->requireBusinessId('expenses');
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['business_id', 'expense_date']);
            $table->index(['business_id', 'category', 'expense_date']);
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
        Schema::table('expenses', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'expense_date']);
            $table->dropIndex(['user_id', 'category', 'expense_date']);
        });
    }

    /**
     * Make the backfilled business_id column NOT NULL.
     */
    private function requireBusinessId(string $name): void
    {
        Schema::table($name, function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Not reversible automatically. Rebuilding the user_id keys would have to guess
     * at data written after the switch to business ownership. Recover by restoring
     * the backup taken before migrating.
     */
    public function down(): void
    {
        throw new RuntimeException('The business ownership migration cannot be rolled back. Restore the pre-migration database backup.');
    }
};
