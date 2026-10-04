<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenshot_capture_stages', function (Blueprint $table): void {
            $table->timestamp('last_occurred_at', 6)->nullable();
        });
        DB::table('screenshot_capture_stages')->update(['last_occurred_at' => DB::raw('occurred_at')]);
        Schema::table('telemetry_events', function (Blueprint $table): void {
            $table->timestamp('occurred_at', 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('screenshot_capture_stages', function (Blueprint $table): void {
            $table->dropColumn('last_occurred_at');
        });
        Schema::table('telemetry_events', function (Blueprint $table): void {
            $table->timestamp('occurred_at')->change();
        });
    }
};
