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
            $table->date('birth_date')->nullable()->after('avatar_path');
            // ISO 3166-1 alpha-2 — clients render the display name/flag locally from this
            // (e.g. Android's Locale("", code).displayCountry), no lookup table needed here.
            $table->string('country_code', 2)->nullable()->after('birth_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'country_code']);
        });
    }
};
