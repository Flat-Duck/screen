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
            // An admin kill-switch on login, distinct from account deletion: deactivating
            // blocks new sessions and revokes existing ones (see StartDeviceSession /
            // SetUserActiveState) but leaves the profile/posts/content visible as normal —
            // unlike delete, which is self-service, has a retention/restore window, and
            // hides content immediately. Deliberately not mass-assignable (absent from
            // User's #[Fillable] attribute), same reasoning as is_admin.
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
