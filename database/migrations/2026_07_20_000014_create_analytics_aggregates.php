<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_product_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('metric_date')->unique();
            $table->unsignedInteger('daily_active_users')->default(0);
            $table->unsignedInteger('registrations')->default(0);
            $table->unsignedInteger('active_creators')->default(0);
            $table->unsignedInteger('screenshots_published')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('opens')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->unsignedBigInteger('follows')->default(0);
            $table->unsignedBigInteger('hides')->default(0);
            $table->unsignedBigInteger('reports')->default(0);
            $table->unsignedInteger('sessions_started')->default(0);
            $table->unsignedInteger('crashed_sessions')->default(0);
            $table->boolean('is_partial')->default(false);
            $table->timestamp('aggregated_at');
            $table->timestamps();
        });

        Schema::create('daily_user_activity', function (Blueprint $table): void {
            $table->id();
            $table->date('activity_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('events_count')->default(0);
            $table->unsignedInteger('unique_posts')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedBigInteger('dwell_ms')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('reposts')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('follows')->default(0);
            $table->unsignedInteger('negative_feedback')->default(0);
            $table->timestamps();
            $table->unique(['activity_date', 'user_id']);
        });

        Schema::create('daily_post_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('metric_date');
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('unique_viewers')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedBigInteger('dwell_ms')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('reposts')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('hides')->default(0);
            $table->unsignedInteger('not_interested')->default(0);
            $table->unsignedInteger('reports')->default(0);
            $table->timestamps();
            $table->unique(['metric_date', 'post_id']);
        });

        Schema::create('user_author_affinities', function (Blueprint $table): void {
            $table->id();
            $table->date('affinity_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->integer('score')->default(0);
            $table->unsignedInteger('positive_events')->default(0);
            $table->unsignedInteger('negative_events')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->timestamp('last_event_at');
            $table->timestamps();
            $table->unique(['affinity_date', 'user_id', 'author_id']);
            $table->index(['user_id', 'score']);
        });

        Schema::create('user_topic_affinities', function (Blueprint $table): void {
            $table->id();
            $table->date('affinity_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('screenshot_categories')->cascadeOnDelete();
            $table->integer('score')->default(0);
            $table->unsignedInteger('positive_events')->default(0);
            $table->unsignedInteger('negative_events')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->timestamp('last_event_at');
            $table->timestamps();
            $table->unique(['affinity_date', 'user_id', 'category_id']);
            $table->index(['user_id', 'score']);
        });

        Schema::create('recommendation_feedback_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->date('metric_date');
            $table->string('surface', 40);
            $table->string('candidate_source', 50)->default('unknown');
            $table->unsignedInteger('unique_users')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('follows')->default(0);
            $table->unsignedInteger('hides')->default(0);
            $table->unsignedInteger('not_interested')->default(0);
            $table->unsignedInteger('reports')->default(0);
            $table->timestamps();
            $table->unique(['metric_date', 'surface', 'candidate_source']);
        });

        Schema::create('retention_cohort_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('cohort_date');
            $table->date('activity_date');
            $table->unsignedSmallInteger('day_number');
            $table->unsignedInteger('cohort_size')->default(0);
            $table->unsignedInteger('retained_users')->default(0);
            $table->boolean('is_partial')->default(false);
            $table->timestamps();
            $table->unique(['cohort_date', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_cohort_metrics');
        Schema::dropIfExists('recommendation_feedback_aggregates');
        Schema::dropIfExists('user_topic_affinities');
        Schema::dropIfExists('user_author_affinities');
        Schema::dropIfExists('daily_post_metrics');
        Schema::dropIfExists('daily_user_activity');
        Schema::dropIfExists('daily_product_metrics');
    }
};
