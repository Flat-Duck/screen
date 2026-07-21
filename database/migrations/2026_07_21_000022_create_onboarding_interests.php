<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interests', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('interest_category', function (Blueprint $table): void {
            $table->foreignId('interest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('screenshot_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('weight')->default(100);
            $table->primary(['interest_id', 'category_id']);
        });

        Schema::create('hashtag_interest', function (Blueprint $table): void {
            $table->foreignId('interest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('weight')->default(100);
            $table->primary(['interest_id', 'hashtag_id']);
        });

        Schema::create('interest_user', function (Blueprint $table): void {
            $table->foreignId('interest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('weight')->default(100);
            $table->string('source', 30)->default('onboarding');
            $table->timestamp('selected_at');
            $table->timestamps();
            $table->primary(['interest_id', 'user_id']);
            $table->index(['user_id', 'source']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('interests_completed_at')->nullable()->after('country_code');
            $table->timestamp('interests_skipped_at')->nullable()->after('interests_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['interests_completed_at', 'interests_skipped_at']);
        });
        Schema::dropIfExists('interest_user');
        Schema::dropIfExists('hashtag_interest');
        Schema::dropIfExists('interest_category');
        Schema::dropIfExists('interests');
    }
};
