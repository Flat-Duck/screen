<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table): void {
            $table->unsignedSmallInteger('protocol_version')->default(1)->after('nonce');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table): void {
            $table->dropColumn('protocol_version');
        });
    }
};
