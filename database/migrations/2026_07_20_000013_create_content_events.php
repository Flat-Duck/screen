<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('surface', 40);
            $table->string('event_type', 40);
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('candidate_source', 50)->nullable();
            $table->uuid('request_id')->nullable();
            $table->json('experiment_assignments')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['post_id', 'event_type', 'occurred_at']);
            $table->index(['author_id', 'event_type', 'occurred_at']);
            $table->index(['surface', 'event_type', 'occurred_at']);
            $table->index('request_id');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_events');
    }
};
