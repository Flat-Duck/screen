<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * First of two migrations refactoring `likes` from a post-only table to a polymorphic
     * one (matching the `reports` table's existing reportable_type/reportable_id shape) so
     * comment likes can reuse the same table/service instead of a parallel comment_likes
     * table. Split into two migrations rather than one: this one adds the new columns and
     * backfills them from `post_id` while both still exist, so a bad backfill is caught
     * before the next migration drops `post_id` for good.
     */
    public function up(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->string('likeable_type')->nullable()->after('post_id');
            $table->unsignedBigInteger('likeable_id')->nullable()->after('likeable_type');
        });

        DB::table('likes')->update(['likeable_type' => Post::class]);
        DB::statement('UPDATE likes SET likeable_id = post_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropColumn(['likeable_type', 'likeable_id']);
        });
    }
};
