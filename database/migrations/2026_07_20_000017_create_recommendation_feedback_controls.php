<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_post_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->timestamps();
            $table->unique(['user_id', 'post_id', 'type']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('recommendation_target_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['user_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('recommendation_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_exclusions');
        Schema::dropIfExists('recommendation_target_feedback');
        Schema::dropIfExists('recommendation_post_feedback');
    }
};
