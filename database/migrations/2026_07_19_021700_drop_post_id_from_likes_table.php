<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Second of two migrations refactoring `likes` to be polymorphic — see
     * 2026_07_19_021645_add_likeable_columns_to_likes_table for why this is split out.
     */
    public function up(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropUnique(['post_id', 'user_id']);
            $table->dropForeign(['post_id']);
            $table->dropColumn('post_id');
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->unique(['likeable_type', 'likeable_id', 'user_id']);
            $table->index(['likeable_type', 'likeable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropUnique(['likeable_type', 'likeable_id', 'user_id']);
            $table->dropIndex(['likeable_type', 'likeable_id']);
            $table->foreignId('post_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE likes SET post_id = likeable_id');

        Schema::table('likes', function (Blueprint $table) {
            $table->unique(['post_id', 'user_id']);
        });
    }
};
