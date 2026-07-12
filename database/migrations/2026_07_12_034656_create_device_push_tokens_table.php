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
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            // Android only ever calls the registering endpoint while authenticated (right
            // after login/register/social auth, or from onNewToken while a session
            // exists) — so unlike the Android-side request doc's original nullable
            // suggestion, this is simplified to always require a user.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fcm_token')->unique();
            $table->string('platform')->default('android');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
    }
};
