<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A recurring invoice is a schedule plus a template. It is not an invoice: it
     * has no number, payment state or totals. Each due occurrence generates an
     * ordinary draft invoice (see invoices.recurring_invoice_id).
     *
     * Occurrences are calculated from start_date and frequency, never from the
     * previous occurrence. next_occurrence_on is the next one to generate; when it
     * is after end_date (inclusive) the schedule is finished.
     */
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('customer_id');
            // Audit metadata only: NULL means system-generated or no associated person.
            $table->foreignId('created_by')->nullable();
            $table->string('name');
            $table->string('status', 20);
            $table->string('frequency', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_occurrence_on');
            // The latest occurrence generated; NULL means nothing has been generated yet.
            $table->date('last_occurrence_on')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);

            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->string('tax_label', 30)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->text('notes')->nullable();

            $table->timestamp('last_generated_at')->nullable();
            $table->string('last_generation_error', 500)->nullable();
            $table->timestamp('last_generation_failed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'customer_id']);
            // Lets invoices reference (business_id, id), so an invoice can only link to its own business's schedule.
            $table->unique(['business_id', 'id']);
            // The scheduler's query: active schedules with an occurrence due.
            $table->index(['status', 'next_occurrence_on']);
            $table->index('created_by');

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['business_id', 'customer_id'])
                ->references(['business_id', 'id'])->on('customers')->restrictOnDelete();
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
        Schema::dropIfExists('recurring_invoices');
    }
};
