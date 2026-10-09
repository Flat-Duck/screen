<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invite_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamps();
            $table->index(['inviter_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invite_links');
    }
};
