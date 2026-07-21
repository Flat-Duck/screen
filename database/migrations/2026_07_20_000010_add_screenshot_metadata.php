<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshot_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('screenshot_categories')->insert(collect([
            'social', 'messaging', 'code', 'gaming', 'shopping', 'finance', 'education', 'work', 'other',
        ])->map(fn (string $slug, int $index): array => [
            'slug' => $slug,
            'name' => str($slug)->headline()->toString(),
            'sort_order' => $index,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->constrained('screenshot_categories')->nullOnDelete();
            $table->string('source_application', 100)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('content_warning', 50)->nullable();
        });

        Schema::table('post_media', function (Blueprint $table): void {
            $table->text('alt_text')->nullable();
            $table->text('ocr_text')->nullable();
            $table->string('ocr_language', 20)->nullable();
            $table->string('ocr_status', 20)->default('pending');
            $table->string('ocr_version', 100)->nullable();
            $table->string('perceptual_hash', 255)->nullable()->index();
            $table->string('safety_status', 20)->default('pending');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropIndex(['perceptual_hash']);
            $table->dropColumn(['alt_text', 'ocr_text', 'ocr_language', 'ocr_status', 'ocr_version', 'perceptual_hash', 'safety_status']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['source_application', 'source_url', 'content_warning']);
        });

        Schema::dropIfExists('screenshot_categories');
    }
};
