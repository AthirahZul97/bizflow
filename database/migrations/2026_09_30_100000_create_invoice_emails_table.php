<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The send history of invoice emails: one row per send request. A row is owned
     * through its invoice (invoice_id -> invoices.business_id), like invoice_items;
     * it has no business_id of its own. requested_by is audit metadata only (NULL
     * means system-generated or no associated person) and never determines ownership.
     */
    public function up(): void
    {
        Schema::create('invoice_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id');
            $table->foreignId('requested_by')->nullable();
            $table->string('recipient_email');
            $table->string('recipient_source', 20);
            $table->string('subject');
            $table->string('status', 20);
            $table->string('status_at_send', 20)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->index(['invoice_id', 'status']);
            $table->index('requested_by');

            // The history is kept: an invoice with sends cannot be deleted (issued invoices never are).
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_emails');
    }
};
