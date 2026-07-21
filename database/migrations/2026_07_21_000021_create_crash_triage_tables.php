<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crash_groups', function (Blueprint $table): void {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->string('name');
            $table->string('exception_class')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fixed_app_version')->nullable();
            $table->unsignedBigInteger('occurrence_count')->default(0);
            $table->unsignedBigInteger('affected_user_count')->default(0);
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::table('telemetry_events', function (Blueprint $table): void {
            $table->foreignId('crash_group_id')->nullable()->after('crash_fingerprint')->constrained('crash_groups')->nullOnDelete();
            $table->index(['crash_group_id', 'received_at']);
        });
        Schema::create('crash_group_users', function (Blueprint $table): void {
            $table->foreignId('crash_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['crash_group_id', 'user_id']);
        });
        Schema::create('crash_group_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crash_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        DB::table('telemetry_events')->whereNotNull('crash_fingerprint')->orderBy('id')->each(function (object $event): void {
            $group = DB::table('crash_groups')->where('fingerprint', $event->crash_fingerprint)->first();
            if (! $group) {
                $id = DB::table('crash_groups')->insertGetId([
                    'fingerprint' => $event->crash_fingerprint, 'name' => $event->name,
                    'exception_class' => $event->exception_class, 'status' => 'open',
                    'occurrence_count' => 0, 'affected_user_count' => 0,
                    'first_seen_at' => $event->occurred_at, 'last_seen_at' => $event->occurred_at,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $id = $group->id;
            }
            DB::table('telemetry_events')->where('id', $event->id)->update(['crash_group_id' => $id]);
            DB::table('crash_groups')->where('id', $id)->update([
                'occurrence_count' => DB::raw('occurrence_count + 1'),
                'first_seen_at' => min($event->occurred_at, DB::table('crash_groups')->where('id', $id)->value('first_seen_at')),
                'last_seen_at' => max($event->occurred_at, DB::table('crash_groups')->where('id', $id)->value('last_seen_at')),
                'updated_at' => now(),
            ]);
            if ($event->user_id !== null) {
                DB::table('crash_group_users')->insertOrIgnore(['crash_group_id' => $id, 'user_id' => $event->user_id]);
                DB::table('crash_groups')->where('id', $id)->update(['affected_user_count' => DB::table('crash_group_users')->where('crash_group_id', $id)->count()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crash_group_notes');
        Schema::dropIfExists('crash_group_users');
        Schema::table('telemetry_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('crash_group_id'));
        Schema::dropIfExists('crash_groups');
    }
};
