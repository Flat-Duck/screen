<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('login_method');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamp('two_factor_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('app_version_name')->nullable();
            $table->unsignedInteger('app_version_code')->nullable();
            $table->string('os_version')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'ended_at']);
            $table->index(['device_id', 'ended_at']);
        });

        DB::statement('CREATE UNIQUE INDEX device_sessions_one_active_per_device ON device_sessions (device_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
