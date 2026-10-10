<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->string('idempotency_key', 150)->nullable()->unique('point_transactions_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->dropUnique('point_transactions_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
