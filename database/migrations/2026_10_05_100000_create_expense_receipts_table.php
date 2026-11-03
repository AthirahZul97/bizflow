<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per uploaded receipt, owned by a business through business_id (required).
     * It holds the stored file's private path, the OCR state and the normalized extraction,
     * and, once the user confirms, the expense that was created (expense_id, unique: a receipt
     * can never create two expenses). Raw provider responses and OCR text are never stored.
     *
     * uploaded_by and confirmed_by are audit metadata only (NULL means system-generated or no
     * associated person) and never determine ownership. counted_at is when the receipt took one
     * unit of the monthly OCR quota; NULL means it holds none (see MonthlyReceiptOcrMeter).
     */
    public function up(): void
    {
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('uploaded_by')->nullable();
            $table->string('status', 20);
            $table->string('original_filename', 255);
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path', 120)->unique();
            $table->string('provider', 30)->nullable();
            $table->string('provider_model', 60)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('extraction')->nullable();
            $table->json('edited_fields')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('expense_id')->nullable()->unique();
            $table->foreignId('confirmed_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'sha256']);
            $table->index(['business_id', 'counted_at']);
            $table->index(['status', 'created_at']);
            $table->index('uploaded_by');
            $table->index('confirmed_by');

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            // Deleting an expense keeps the receipt (its file and audit trail); it just loses the link.
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * For development only. Production recovery is restoring the backup taken before migrating.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_receipts');
    }
};
