<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('customer_id');
            $table->unsignedInteger('invoice_sequence')->nullable();
            $table->string('invoice_number', 30)->nullable();
            $table->string('status', 20);
            $table->date('issue_date');
            $table->date('due_date');
            $table->char('currency_code', 3);

            // Billing details copied from the customer; frozen once the invoice is issued.
            $table->string('customer_name');
            $table->string('customer_company_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_address_line_1')->nullable();
            $table->string('customer_address_line_2')->nullable();
            $table->string('customer_city', 100)->nullable();
            $table->string('customer_state', 100)->nullable();
            $table->string('customer_postcode', 20)->nullable();
            $table->string('customer_country', 100)->nullable();

            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->string('tax_label', 30)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->text('notes')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->unique(['user_id', 'invoice_number']);
            $table->unique(['user_id', 'invoice_sequence']);
            $table->index(['user_id', 'status', 'due_date']);
            $table->index(['user_id', 'issue_date']);
            $table->index('customer_id');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
