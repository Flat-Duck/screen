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
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mentioner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mentionable_type');
            $table->unsignedBigInteger('mentionable_id');
            $table->timestamps();

            $table->unique(['mentionable_type', 'mentionable_id', 'mentioned_user_id']);
            $table->index(['mentionable_type', 'mentionable_id']);
            $table->index('mentioned_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
