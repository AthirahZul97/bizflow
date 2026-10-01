<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The lines of a recurring invoice template. Same shape as invoice_items without
     * line_total, which is calculated when an invoice is generated. Owned through the
     * recurring invoice (no business_id here), like invoice_items.
     */
    public function up(): void
    {
        Schema::create('recurring_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id');
            $table->foreignId('product_id')->nullable();

            // The template's own values; a product only fills blanks when the template is saved.
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->unique(['recurring_invoice_id', 'position']);
            $table->index('product_id');

            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * For development only. Production recovery is restoring the backup taken before migrating.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_items');
    }
};
