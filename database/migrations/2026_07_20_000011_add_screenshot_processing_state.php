<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->string('hash_status', 20)->default('pending');
            $table->string('hash_version', 100)->nullable();
            $table->string('safety_version', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropColumn(['hash_status', 'hash_version', 'safety_version']);
        });
    }
};
