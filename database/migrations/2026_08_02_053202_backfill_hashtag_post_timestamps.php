<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Post::hashtags()/Hashtag::posts() never declared ->withTimestamps(), so every hashtag_post
 * row ever written via SyncPostHashtags::sync() has NULL created_at/updated_at — nothing read
 * that column before hashtags/HashtagService::trending() started ranking by it. Backfills
 * existing rows from their post's own created_at (the closest real value to "when this post
 * got tagged"); both model relations now declare withTimestamps() so new rows self-populate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hashtag_post')->whereNull('created_at')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $postCreatedAt = DB::table('posts')->where('id', $row->post_id)->value('created_at');
                $timestamp = $postCreatedAt ?? now();

                DB::table('hashtag_post')->where('id', $row->id)->update([
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Data backfill only — nothing meaningful to reverse.
    }
};
