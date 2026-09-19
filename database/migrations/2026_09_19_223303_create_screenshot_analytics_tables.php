<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshot_captures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('detected_at')->nullable()->index();
            $table->timestamp('created_at');
            $table->index(['user_id', 'detected_at']);
        });
        Schema::create('screenshot_capture_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('capture_id')->constrained('screenshot_captures')->cascadeOnDelete();
            $table->string('stage', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->unique(['capture_id', 'stage']);
            $table->index(['stage', 'occurred_at']);
        });
        Schema::create('screenshot_library_snapshots', function (Blueprint $table) {
            $table->foreignId('device_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('screenshot_count')->nullable();
            $table->string('coverage', 20);
            $table->timestamp('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshot_library_snapshots');
        Schema::dropIfExists('screenshot_capture_stages');
        Schema::dropIfExists('screenshot_captures');
    }
};
