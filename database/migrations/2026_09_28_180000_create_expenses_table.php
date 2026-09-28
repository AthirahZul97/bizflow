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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->date('expense_date');
            $table->string('category', 30);
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->string('payee')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Created before the foreign key so MySQL reuses them instead of adding a separate user_id index.
            $table->index(['user_id', 'expense_date']);
            $table->index(['user_id', 'category', 'expense_date']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
