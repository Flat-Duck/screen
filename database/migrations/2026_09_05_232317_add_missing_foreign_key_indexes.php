<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres does not index a foreign key column just because it is a foreign key — unlike
     * MySQL, `foreignId()->constrained()` leaves the column bare. Every one of these columns is
     * either joined on a hot read path (feeds, profiles, the comment reply tree, trending) or
     * sits on the child side of an `ON DELETE CASCADE`, where an unindexed FK makes Postgres
     * sequentially scan the whole child table for each parent row deleted — which is what
     * `users:prune-deleted` and `posts:prune-deleted` do in bulk.
     *
     * `posts.created_at` and `hashtag_post.created_at` are not foreign keys; they are here
     * because the feed orders by one and the trending window filters on the other, and neither
     * had an index of any kind.
     *
     * Built while the tables are still small enough that this is instant. It is written to stay
     * safe once they are not: on Postgres each index is created CONCURRENTLY, which does not
     * take the ACCESS EXCLUSIVE lock that would block writes to the table for the duration.
     *
     * @var list<array{table: string, column: string}>
     */
    private const INDEXES = [
        ['table' => 'posts', 'column' => 'user_id'],
        ['table' => 'posts', 'column' => 'created_at'],
        ['table' => 'likes', 'column' => 'user_id'],
        ['table' => 'comments', 'column' => 'user_id'],
        ['table' => 'comments', 'column' => 'parent_id'],
        ['table' => 'hashtag_post', 'column' => 'post_id'],
        ['table' => 'hashtag_post', 'column' => 'created_at'],
        ['table' => 'collection_items', 'column' => 'post_id'],
        ['table' => 'devices', 'column' => 'user_id'],
        ['table' => 'telemetry_events', 'column' => 'device_session_id'],
    ];

    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction, and Postgres supports
     * transactional DDL so Laravel would otherwise wrap this migration in one.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            $name = $this->indexName($index['table'], $index['column']);

            if ($this->isPostgres()) {
                DB::statement(sprintf(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
                    $name,
                    $index['table'],
                    $index['column'],
                ));

                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index, $name): void {
                $table->index($index['column'], $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index) {
            $name = $this->indexName($index['table'], $index['column']);

            if ($this->isPostgres()) {
                DB::statement(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $name));

                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }

    /**
     * Matches Laravel's own generated name, so the Postgres and SQLite paths produce the same
     * index and `down()` can drop either of them by the same name.
     */
    private function indexName(string $table, string $column): string
    {
        return "{$table}_{$column}_index";
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
