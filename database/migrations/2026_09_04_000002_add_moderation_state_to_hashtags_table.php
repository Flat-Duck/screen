<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives hashtags the same kind of ranking lever posts already have via
     * `posts.recommendation_eligible` — before this, an abusive tag that started trending
     * could only be addressed by deleting its posts one at a time.
     */
    public function up(): void
    {
        Schema::table('hashtags', function (Blueprint $table) {
            $table->string('moderation_state')->default('clear')->index();
            $table->text('moderation_reason')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hashtags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moderated_by');
            // SQLite (the test connection) refuses to drop a column an index still
            // references, so the index has to go first.
            $table->dropIndex(['moderation_state']);
            $table->dropColumn(['moderation_state', 'moderation_reason', 'moderated_at']);
        });
    }
};
