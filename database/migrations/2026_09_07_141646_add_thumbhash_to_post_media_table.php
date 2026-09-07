<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A ThumbHash: ~25 bytes that decode to a blurred impression of the image, small enough to
     * ship inside the feed JSON. It is what lets a card show something the instant the response
     * lands, instead of a grey box for however long the image itself takes — which on a cellular
     * connection is the whole of the perceived load time.
     *
     * Nullable with no status column of its own: null means "not computed yet", which every
     * consumer already has to handle for the rows that predate this. 64 is roomy — base64 of a
     * ~25-byte hash is around 34 characters, and the format's own ceiling is well under 64.
     */
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->string('thumbhash', 64)->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropColumn('thumbhash');
        });
    }
};
