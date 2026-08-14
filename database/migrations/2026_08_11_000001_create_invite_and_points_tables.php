<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable at the schema level (not backed by a NOT NULL constraint) even though
            // User::booted()'s creating() listener guarantees every new row gets one — same
            // "app-level guarantee, not a DB-level one" convention this table already uses for
            // username (also nullable, also treated as always-eventually-present by the app).
            $table->string('invite_code', 12)->nullable()->unique()->after('username');
            $table->unsignedInteger('points_balance')->default(0)->after('invite_code');
        });

        // Backfill any pre-existing rows — User::booted()'s listener only covers new inserts
        // going forward. Self-contained generation logic here rather than depending on
        // App\Services\InviteCodeService, since migrations shouldn't couple to application
        // code that might change shape later (same reasoning as every other migration in this
        // codebase never importing an App\ class).
        DB::table('users')->whereNull('invite_code')->select('id')->orderBy('id')->lazyById(500)
            ->each(function (object $row): void {
                DB::table('users')->where('id', $row->id)->update(['invite_code' => $this->generateUniqueCode()]);
            });

        Schema::create('user_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            // One inviter per invitee, ever — enforced by the unique() below, not just app logic.
            $table->foreignId('invitee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_used', 12);
            $table->timestamp('redeemed_at');
            $table->timestamp('points_awarded_at')->nullable();
            $table->unsignedInteger('points_awarded')->nullable();
            $table->timestamps();

            $table->unique('invitee_user_id');
            // Matches AwardMaturedInvitePoints' own scan shape (unmatured rows past their window).
            $table->index(['points_awarded_at', 'redeemed_at']);
            // Matches "my invites" list queries (GET /v1/me/invites), newest first.
            $table->index(['inviter_user_id', 'id']);
        });

        Schema::create('point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 40);
            $table->foreignId('user_invite_id')->nullable()->constrained('user_invites')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('user_invites');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['invite_code', 'points_balance']);
        });
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (DB::table('users')->where('invite_code', $code)->exists());

        return $code;
    }
};
