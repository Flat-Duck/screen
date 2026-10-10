<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->unique(['user_invite_id', 'reason'], 'point_transactions_invite_reason_unique');
        });
    }

    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->dropUnique('point_transactions_invite_reason_unique');
        });
    }
};
