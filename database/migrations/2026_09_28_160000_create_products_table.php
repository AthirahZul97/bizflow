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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type', 20);
            $table->string('name');
            $table->string('sku', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Created before the foreign key so MySQL reuses them instead of adding a separate user_id index.
            $table->index(['user_id', 'name']);
            $table->unique(['user_id', 'sku']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
