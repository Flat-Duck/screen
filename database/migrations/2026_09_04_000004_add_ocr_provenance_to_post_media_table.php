<?php

use App\Models\PostMedia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            // Survives publish. `media_analysis_items.ocr_source` was the only record of
            // whether text came from the device or the server, and those rows are deleted at
            // publish — so a published post carried no provenance at all.
            $table->string('ocr_source', 10)->nullable()->after('ocr_version')->index();
            $table->unsignedInteger('ocr_duration_ms')->nullable()->after('ocr_source');
        });

        // The staged path measures its own extraction; carried across at publish so a
        // device-sourced post has the same timing data as a server-sourced one.
        Schema::table('media_analysis_items', function (Blueprint $table): void {
            $table->unsignedInteger('ocr_duration_ms')->nullable()->after('ocr_status');
        });

        /*
         * `ocr_status = ready` did not mean OCR ran. Seeded and imported rows were written
         * straight to `ready` with no `ocr_version`, so "ran and found no text" and "never
         * ran at all" were indistinguishable — which silently corrupts every rate computed
         * over them. A row that produced no version never ran; say so.
         */
        DB::table('post_media')
            ->where('ocr_status', PostMedia::PROCESSING_READY)
            ->whereNull('ocr_version')
            ->update(['ocr_status' => PostMedia::PROCESSING_SKIPPED]);
    }

    public function down(): void
    {
        DB::table('post_media')
            ->where('ocr_status', PostMedia::PROCESSING_SKIPPED)
            ->update(['ocr_status' => PostMedia::PROCESSING_READY]);

        Schema::table('media_analysis_items', function (Blueprint $table): void {
            $table->dropColumn('ocr_duration_ms');
        });

        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropIndex(['ocr_source']);
            $table->dropColumn(['ocr_source', 'ocr_duration_ms']);
        });
    }
};
