<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task_key', 64)->unique();
            $table->string('task_name');
            $table->string('status', 20);
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();
        });

        Schema::create('operations_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->index();
            $table->json('checks');
            $table->json('metrics');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });

        Schema::create('api_request_metrics', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('minute')->unique();
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('rate_limited')->default(0);
            $table->unsignedBigInteger('total_duration_ms')->default(0);
            $table->unsignedInteger('max_duration_ms')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_metrics');
        Schema::dropIfExists('operations_health_snapshots');
        Schema::dropIfExists('scheduled_task_runs');
    }
};
