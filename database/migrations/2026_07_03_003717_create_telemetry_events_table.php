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
        Schema::create('telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_session_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('event_uuid');
            $table->string('kind'); // event | error | fatal_crash
            $table->string('name');
            $table->timestamp('occurred_at'); // device-reported
            $table->timestamp('received_at'); // server-stamped, guards against device clock skew
            $table->json('extras')->nullable();
            $table->json('breadcrumbs')->nullable();
            // Present only when kind != 'event'
            $table->string('error_tag')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('error_message')->nullable();
            $table->text('stack_trace')->nullable();
            $table->string('thread_name')->nullable();
            $table->boolean('is_fatal')->nullable();
            $table->string('app_version_name')->nullable();
            $table->unsignedInteger('app_version_code')->nullable();
            $table->string('build_type')->nullable();
            $table->string('os_version')->nullable();
            $table->string('crash_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->index(['device_id', 'kind']);
            $table->unique(['device_id', 'event_uuid']);
            $table->index('occurred_at');
            $table->index(['crash_fingerprint', 'app_version_code']);
            $table->index(['user_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
    }
};
