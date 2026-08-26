<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            // Carried over from media_analysis_items.source_disk at publish time — null means
            // the legacy local media_disk. Every reader that hardcodes
            // Storage::disk(config('social.media_disk')) against a PostMedia path needs to fall
            // back to `$media->source_disk ?? config('social.media_disk')` instead — see
            // docs/SECURITY.md §12 (ImageProcessingService::generateThumbnail, DifferenceHashService).
            $table->string('source_disk')->nullable()->after('original_path');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropColumn('source_disk');
        });
    }
};
