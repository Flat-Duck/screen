<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Postgres leaves a foreign key column unindexed unless one is created explicitly, so these
 * indexes are easy to drop by accident — nothing in the schema definition requires them and
 * no query fails without them, they just get slower. This pins the ones on read paths and on
 * cascading deletes.
 */
class ForeignKeyIndexesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{string, string}> */
    public static function indexedColumns(): array
    {
        return [
            'posts by author' => ['posts', 'user_id'],
            'posts by recency' => ['posts', 'created_at'],
            'likes by user' => ['likes', 'user_id'],
            'comments by author' => ['comments', 'user_id'],
            'comment reply tree' => ['comments', 'parent_id'],
            'hashtag_post by post' => ['hashtag_post', 'post_id'],
            'hashtag_post trending window' => ['hashtag_post', 'created_at'],
            'collection_items by post' => ['collection_items', 'post_id'],
            'devices by owner' => ['devices', 'user_id'],
            'telemetry by device session' => ['telemetry_events', 'device_session_id'],
        ];
    }

    #[DataProvider('indexedColumns')]
    public function test_hot_path_columns_are_indexed(string $table, string $column): void
    {
        $indexed = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['columns'] === [$column]);

        $this->assertTrue($indexed, "{$table}.{$column} has no single-column index.");
    }
}
