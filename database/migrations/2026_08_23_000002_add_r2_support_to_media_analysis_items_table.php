<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_analysis_items', function (Blueprint $table): void {
            $table->foreignId('upload_id')->nullable()->after('media_analysis_id')->constrained()->nullOnDelete();
            // Null means the legacy local media_disk path (see config('social.media_disk'));
            // set explicitly for R2-backed items. See docs/SECURITY.md §12.
            $table->string('source_disk')->nullable()->after('original_path');
            $table->string('ocr_source', 10)->default('server')->after('ocr_status');
            // pending (sampled, not yet compared at publish) | verified_match | verified_mismatch.
            // Null for both the legacy path and the trusted-not-sampled path (device-source items
            // that were never sampled have nothing to compare against).
            $table->string('verification_status', 20)->nullable()->after('ocr_source');
            // The device's claimed OCR text — preserved separately from ocr_text so a sampled
            // item can still be diffed after the server's own Tesseract result overwrites
            // ocr_text (server always wins as the canonical value — see docs/SECURITY.md §4/§12).
            $table->text('device_ocr_text')->nullable()->after('verification_status');
        });

        // Not knowable without either downloading the R2 object or the client reporting them at
        // commit time (which it doesn't yet) — see docs/SECURITY.md §12's implementation notes.
        Schema::table('media_analysis_items', function (Blueprint $table): void {
            $table->unsignedInteger('width')->nullable()->change();
            $table->unsignedInteger('height')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_analysis_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('upload_id');
            $table->dropColumn(['source_disk', 'ocr_source', 'verification_status', 'device_ocr_text']);
            $table->unsignedInteger('width')->nullable(false)->change();
            $table->unsignedInteger('height')->nullable(false)->change();
        });
    }
};
