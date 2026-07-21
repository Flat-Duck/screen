<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope', 30)->default('product');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('kill_switch')->default(false);
            $table->unsignedSmallInteger('rollout_basis_points')->default(10000);
            $table->json('payload')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'starts_at', 'ends_at']);
        });

        Schema::create('experiments', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope', 30)->default('product');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('kill_switch')->default(false);
            $table->unsignedSmallInteger('allocation_basis_points')->default(10000);
            $table->json('variants');
            $table->string('salt', 100);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'starts_at', 'ends_at']);
        });

        Schema::create('experiment_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('experiment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('variant', 50);
            $table->unsignedInteger('experiment_version');
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->unique(['experiment_id', 'user_id', 'experiment_version']);
            $table->index(['experiment_id', 'variant', 'experiment_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('experiments');
        Schema::dropIfExists('feature_flags');
    }
};
