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
        Schema::create('mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('muter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('muted_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['muter_id', 'muted_id']);
            $table->index('muted_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mutes');
    }
};
