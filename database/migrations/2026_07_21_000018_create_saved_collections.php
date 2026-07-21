<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('position');
            $table->string('visibility', 20)->default('private');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'position']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('collection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('note', 1000)->nullable();
            $table->unsignedInteger('position');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['collection_id', 'post_id']);
            $table->index(['collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
    }
};
