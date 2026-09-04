<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The durable half of the device-OCR trust loop.
     *
     * Every comparison the loop already performed lived on `media_analysis_items`, which are
     * deleted at publish (PublishMediaAnalysis) and expire after 30 minutes regardless — so
     * the signal was computed and immediately thrown away, and there was no way to ask
     * whether device OCR is getting better. This table is what survives.
     *
     * Deliberately stores hashes, counts and scores rather than text. The rows are permanent
     * and OCR text is user content that routinely contains credentials and IDs; a permanent,
     * queryable copy of it is exactly what should not exist. Everything the metrics need —
     * agreement, similarity, drift by version or language — is derivable without it.
     */
    public function up(): void
    {
        Schema::create('ocr_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_media_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ocr_source', 10)->index();
            // match | mismatch — the sampled comparison; unverified — a trusted account's
            // claim taken as canonical without the server ever re-reading the image.
            $table->string('verdict', 20)->index();

            $table->char('device_text_hash', 64)->nullable();
            $table->char('server_text_hash', 64)->nullable();
            $table->unsignedInteger('device_char_count')->nullable();
            $table->unsignedInteger('server_char_count')->nullable();

            // Real token-level agreement, 0.0000-1.0000. The existing verdict only compares
            // which CategoryMatcher category each text produced, so two entirely different
            // texts that both map to "Social" count as a match — fine for catching a lying
            // device, useless as an accuracy measure. This is the accuracy measure.
            $table->decimal('similarity', 5, 4)->nullable();

            $table->boolean('category_matched')->nullable();
            $table->foreignId('device_category_id')->nullable()->constrained('screenshot_categories')->nullOnDelete();
            $table->foreignId('server_category_id')->nullable()->constrained('screenshot_categories')->nullOnDelete();

            $table->string('engine_version', 100)->nullable();
            $table->string('ocr_language', 20)->nullable();

            // The tier movement this comparison caused — the trust loop's history, which
            // user_ocr_trust cannot answer because it only ever holds current state.
            $table->string('trust_tier_before', 20)->nullable();
            $table->string('trust_tier_after', 20)->nullable();

            $table->timestamps();
            $table->index(['created_at', 'verdict']);
            $table->index(['ocr_source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_verifications');
    }
};
