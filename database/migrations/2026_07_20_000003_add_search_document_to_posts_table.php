<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->text('searchable_text')->default('')->after('caption');
        });

        DB::table('posts')->whereNotNull('caption')->update([
            'searchable_text' => DB::raw('caption'),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('posts', function (Blueprint $table) {
                $table->fullText('searchable_text')->language('english');
            });
        } elseif (DB::getDriverName() === 'mysql') {
            Schema::table('posts', function (Blueprint $table) {
                $table->fullText('searchable_text');
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropFullText(['searchable_text']);
            });
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('searchable_text');
        });
    }
};
