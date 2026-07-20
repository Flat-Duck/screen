<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_hidden_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('original_value');
            $table->string('normalized_value', 200);
            $table->char('normalized_hash', 64);
            $table->string('type')->default('word');
            $table->timestamps();
            $table->unique(['user_id', 'normalized_hash']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('content_filter_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hidden_term_id')->nullable()->constrained('user_hidden_terms')->cascadeOnDelete();
            $table->morphs('filterable');
            $table->string('reason');
            $table->timestamps();
            $table->unique(['user_id', 'filterable_type', 'filterable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_filter_matches');
        Schema::dropIfExists('user_hidden_terms');
    }
};
