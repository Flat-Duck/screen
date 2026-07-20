<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('target');
            $table->char('open_key', 64)->nullable()->unique();
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('report_count')->default(0)->index();
            $table->timestamp('last_reported_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('moderation_case_id')->nullable()->constrained('moderation_cases')->nullOnDelete();
            $table->index('moderation_case_id');
        });
        Schema::create('moderation_case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('user_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moderation_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('recommendation_eligible')->default(true)->index();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();
        });

        DB::table('reports')
            ->where('status', 'pending')
            ->select(['reportable_type', 'reportable_id', DB::raw('COUNT(*) as report_count'), DB::raw('MAX(created_at) as last_reported_at')])
            ->groupBy('reportable_type', 'reportable_id')
            ->orderBy('reportable_type')
            ->orderBy('reportable_id')
            ->each(function (object $group): void {
                $caseId = DB::table('moderation_cases')->insertGetId([
                    'target_type' => $group->reportable_type,
                    'target_id' => $group->reportable_id,
                    'open_key' => hash('sha256', $group->reportable_type.':'.$group->reportable_id),
                    'status' => 'open',
                    'priority' => 'normal',
                    'report_count' => $group->report_count,
                    'last_reported_at' => $group->last_reported_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('reports')->where('status', 'pending')
                    ->where('reportable_type', $group->reportable_type)
                    ->where('reportable_id', $group->reportable_id)
                    ->update(['moderation_case_id' => $caseId]);
            });
    }

    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['recommendation_eligible', 'moderated_at', 'moderation_reason']));
        Schema::dropIfExists('user_warnings');
        Schema::dropIfExists('moderation_case_notes');
        Schema::table('reports', fn (Blueprint $table) => $table->dropConstrainedForeignId('moderation_case_id'));
        Schema::dropIfExists('moderation_cases');
    }
};
