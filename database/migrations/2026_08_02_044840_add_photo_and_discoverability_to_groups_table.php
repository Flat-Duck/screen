<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('description');
            // Independent of `visibility` — a private group can still choose whether it's
            // findable in search once someone has a direct link/invite, matching the Android
            // client's "Open the group" toggle (see docs/frontend-handoff.md).
            $table->boolean('is_discoverable')->default(true)->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'is_discoverable']);
        });
    }
};
