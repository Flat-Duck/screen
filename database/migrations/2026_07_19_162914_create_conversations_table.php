<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deliberately no participant columns here — those live in
     * `conversation_participants` (see the next migration) so the schema stays additive
     * for a future group-chat feature, even though the service layer only ever creates
     * exactly 2 participants in v1. `last_message_at` is denormalized so the conversation
     * list can be ordered/cursor-paginated without a join against `messages`.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('last_message_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
