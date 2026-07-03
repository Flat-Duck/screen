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
            $table->uuid('event_uuid')->unique();
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
            $table->timestamps();

            $table->index(['device_id', 'kind']);
            $table->index('occurred_at');
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
