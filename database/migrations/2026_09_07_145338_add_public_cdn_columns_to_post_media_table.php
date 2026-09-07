<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a copy of this media lives in the public bucket, when it has been published there.
     *
     * `public_token` is a stored random value, deliberately not derived from the id or an HMAC of
     * it. That makes revocation a simple "rotate the token and delete the object", with no
     * dependence on APP_KEY and no way to enumerate other media from one known URL.
     *
     * All four are null for media that is not published publicly, which is every row until the
     * feature is switched on — and again immediately after an unpublish. `public_path` being set
     * is the single signal `PostMedia::deliveryUrl()` keys off, which is what keeps URL minting
     * free of extra queries and makes invalidation a write rather than a read.
     */
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->char('public_token', 32)->nullable()->unique()->after('thumbhash');
            $table->string('public_path')->nullable()->after('public_token');
            $table->string('public_thumbnail_path')->nullable()->after('public_path');
            $table->timestamp('published_publicly_at')->nullable()->after('public_thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            // SQLite (the test connection) refuses to drop a column an index still references.
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'public_path', 'public_thumbnail_path', 'published_publicly_at']);
        });
    }
};
