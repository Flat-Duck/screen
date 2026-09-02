<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slugs are duplicated from PrivateSaveFolder::DEFAULTS on purpose — a migration is frozen
     * history and must keep producing the same rows even if the model's defaults later change.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'general' => 'General',
        'business' => 'Business',
        'memes' => 'Memes',
    ];

    public function up(): void
    {
        Schema::create('private_save_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 50);
            $table->string('name', 60);
            // True for the three folders every account starts with. They may be renamed but never
            // deleted, which is what guarantees a save always has somewhere to live.
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'position']);
        });

        Schema::table('private_saves', function (Blueprint $table): void {
            $table->foreignId('folder_id')->nullable()->constrained('private_save_folders')->nullOnDelete();
            $table->index(['user_id', 'folder_id', 'id']);
        });

        $now = now();
        DB::table('users')->select('id')->orderBy('id')->chunk(500, function ($users) use ($now): void {
            $rows = [];
            foreach ($users as $user) {
                $position = 0;
                foreach (self::DEFAULTS as $slug => $name) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'slug' => $slug,
                        'name' => $name,
                        'is_default' => true,
                        'position' => $position++,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            DB::table('private_save_folders')->insert($rows);
        });

        // Everything already on file lands in General, so no save is left unfiled and clients can
        // treat folder_id as always present.
        DB::statement(<<<'SQL'
            UPDATE private_saves
               SET folder_id = (SELECT id
                                  FROM private_save_folders
                                 WHERE private_save_folders.user_id = private_saves.user_id
                                   AND private_save_folders.slug = 'general')
             WHERE folder_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('private_saves', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'folder_id', 'id']);
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('private_save_folders');
    }
};
