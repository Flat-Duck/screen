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
        // A short-lived proof that `code` was validated — not a redemption. Layered in front of
        // the existing unlimited-use `users.invite_code` referral system (see InviteCodeService)
        // so the signup screen doesn't have to re-validate a raw code the invite-gate screen
        // already confirmed; the underlying code stays reusable by anyone else regardless of how
        // many reservations exist for it. `ticket` is what the client actually carries forward.
        Schema::create('invite_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->index();
            $table->string('ticket', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invite_reservations');
    }
};
