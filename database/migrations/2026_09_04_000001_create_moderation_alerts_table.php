<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('severity')->index();
            $table->string('state')->default('open')->index();

            // What the alert is about. Nullable because a queue-wide alert (an SLA breach
            // across the whole backlog) has no single target.
            $table->nullableMorphs('target');

            // Same nulled-on-resolve unique key as ModerationCase::open_key: while an alert
            // is open a re-detection of the same condition updates it instead of stacking a
            // duplicate, but once resolved the same condition may legitimately alert again.
            $table->char('open_key', 64)->nullable()->unique();

            $table->string('title');
            $table->json('context')->nullable();

            $table->foreignId('moderation_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_detected_at')->nullable()->index();
            $table->timestamps();

            $table->index(['state', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_alerts');
    }
};
