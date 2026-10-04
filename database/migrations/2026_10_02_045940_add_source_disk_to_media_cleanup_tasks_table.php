<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_cleanup_tasks', function (Blueprint $table): void {
            $table->string('source_disk')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_cleanup_tasks', function (Blueprint $table): void {
            $table->dropColumn('source_disk');
        });
    }
};
