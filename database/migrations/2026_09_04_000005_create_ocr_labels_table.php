<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human ground truth for OCR quality.
     *
     * Everything else measured so far is agreement between two machines, which cannot tell
     * you whether either was right — two engines can be confidently wrong in the same way,
     * and a language neither can read scores as perfect agreement on empty output. A person
     * comparing the extraction against the actual image is the only thing that grounds it.
     */
    public function up(): void
    {
        Schema::create('ocr_labels', function (Blueprint $table): void {
            $table->id();
            // Kept when the media goes away: the post may be deleted, but the evidence about
            // how the engine performed is still valid for the aggregate.
            $table->foreignId('post_media_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verdict', 20)->index();
            $table->text('notes')->nullable();

            /*
             * A label judges one specific extraction, not the image forever. These snapshot
             * what was actually being judged, so a later re-run (a new engine version, or
             * eng -> eng+ara) does not silently inherit ground truth collected about output
             * it never produced. `ocr_text_hash` is what makes a label stale rather than
             * wrong — it is the hash of the empty string when there was no text, never null,
             * so the unique index below actually bites.
             */
            $table->char('ocr_text_hash', 64);
            $table->unsignedInteger('ocr_char_count')->default(0);
            $table->string('ocr_source', 10)->nullable()->index();
            $table->string('engine_version', 100)->nullable();
            $table->string('ocr_language', 20)->nullable();

            $table->timestamps();

            // One verdict per person per extraction — but a new extraction of the same image
            // is a new thing to judge, and two people may deliberately label the same one so
            // disagreement between reviewers is visible.
            $table->unique(['post_media_id', 'labeled_by', 'ocr_text_hash'], 'ocr_labels_unique_per_extraction');
            $table->index(['created_at', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_labels');
    }
};
