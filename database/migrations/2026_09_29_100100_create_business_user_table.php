<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Membership of a user in a business, with their role (App\Enums\BusinessRole).
     * Deleting a user who belongs to a business is refused, so a business is never
     * left without its owner; account deletion must be handled deliberately.
     */
    public function up(): void
    {
        Schema::create('business_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('user_id');
            $table->string('role', 20);
            $table->timestamps();

            // Created before the foreign keys so MySQL reuses them instead of adding separate indexes.
            $table->unique(['business_id', 'user_id']);
            $table->index('user_id');

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_user');
    }
};
