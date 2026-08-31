<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_saves', function (Blueprint $table): void {
            // Null identifies a legacy row stored on the then-current SOCIAL_MEDIA_DISK.
            // Deployment migrates those objects before setting this to the private disk.
            $table->string('source_disk')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('private_saves', function (Blueprint $table): void {
            $table->dropColumn('source_disk');
        });
    }
};
