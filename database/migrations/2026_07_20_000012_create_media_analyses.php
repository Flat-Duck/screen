<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_analyses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cleanup_task_id')->nullable()->constrained('media_cleanup_tasks')->nullOnDelete();
            $table->string('directory')->unique();
            $table->string('status', 20)->default('processing');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('media_analysis_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_analysis_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('original_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->text('alt_text')->nullable();
            $table->text('ocr_text')->nullable();
            $table->string('ocr_language', 20)->nullable();
            $table->string('ocr_status', 20)->default('pending');
            $table->string('safety_status', 20)->default('pending');
            $table->string('analysis_version', 100)->nullable();
            $table->json('findings')->nullable();
            $table->timestamps();
            $table->unique(['media_analysis_id', 'position']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->timestamp('safety_acknowledged_at')->nullable();
            $table->string('safety_analysis_version', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn(['safety_acknowledged_at', 'safety_analysis_version']);
        });
        Schema::dropIfExists('media_analysis_items');
        Schema::dropIfExists('media_analyses');
    }
};
