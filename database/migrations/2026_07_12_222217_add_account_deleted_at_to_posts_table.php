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
        Schema::table('posts', function (Blueprint $table) {
            // Distinguishes "soft-deleted because the whole account was deleted"
            // (AccountService) from "the user deleted this specific post on its own"
            // (PostService::deletePost) — both set `deleted_at`, but only the former
            // sets this too. `users:restore` only revives posts where this is set, so
            // an account restore doesn't also resurrect content the user had
            // deliberately deleted before ever deleting the account.
            $table->timestamp('account_deleted_at')->nullable()->after('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('account_deleted_at');
        });
    }
};
