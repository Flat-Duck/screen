<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('purge_status')->nullable()->after('account_deleted_at');
            $table->timestamp('purge_attempted_at')->nullable()->after('purge_status');
            $table->text('purge_error')->nullable()->after('purge_attempted_at');
            $table->index(['purge_status', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['purge_status', 'deleted_at']);
            $table->dropColumn(['purge_status', 'purge_attempted_at', 'purge_error']);
        });
    }
};
