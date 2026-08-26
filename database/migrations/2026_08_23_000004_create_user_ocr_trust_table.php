<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately not user_restrictions (see docs/SECURITY.md §12's implementation notes):
        // that table models admin-issued enforcement actions with a required human actor
        // (UserRestrictionService::create()) — this is an automated, purely-informational rolling
        // counter, a different kind of thing entirely.
        Schema::create('user_ocr_trust', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('trust_tier', 20)->default('new');
            $table->unsignedInteger('consecutive_verified_count')->default(0);
            $table->timestamp('last_mismatch_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ocr_trust');
    }
};
