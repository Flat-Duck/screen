<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable-safe: MySQL/Postgres/SQLite all allow any number of NULL rows
            // under a unique index, only non-null values are compared. Closes the race
            // where two users request the same new email — without this, the second
            // request would only fail (with an uncaught 500) at *confirmation* time,
            // whichever one lands second on the `email` column's own unique index.
            $table->unique('pending_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pending_email']);
        });
    }
};
