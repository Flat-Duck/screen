<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('upload_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Not populated yet — no request path today authenticates as a User *and* carries a
            // device identity at the same time (see docs/SECURITY.md §6/Phase 4). Column exists
            // now so Phase 4 device-signing/ban-tracking doesn't need another migration later.
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('object_key');
            // Single-use replay guard, bound into the signed digest starting Phase 4 — not
            // consumed by anything yet; Phase 1's replay protection is just the status
            // state machine below (a second commit on the same upload_id is rejected outright).
            $table->string('nonce', 64)->unique();
            $table->string('image_sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 40)->nullable();
            // uploading -> uploaded -> verified -> published, or -> rejected from uploaded.
            // Only uploading/uploaded/rejected are set by this migration's own code today;
            // verified/published belong to the not-yet-built spot-check (§4) and publish wiring.
            $table->string('status', 20)->default('uploading');
            $table->longText('ocr_text')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
