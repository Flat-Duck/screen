<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('visibility_state')->default('visible')->index();
            $table->string('moderation_state')->default('clear')->index();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();
        });

        DB::table('users')->where('is_active', false)->update([
            'visibility_state' => 'hidden',
            'moderation_state' => 'suspended',
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['visibility_state']);
            $table->dropIndex(['moderation_state']);
            $table->dropColumn(['visibility_state', 'moderation_state', 'moderated_at', 'moderation_reason']);
        });
    }
};
