<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreDeletedAccount
{
    public function __invoke(int $userId): RestoreDeletedAccountResult
    {
        return DB::transaction(function () use ($userId): RestoreDeletedAccountResult {
            $user = User::onlyTrashed()->findOrFail($userId);
            $restoredPosts = $user->posts()->onlyTrashed()->whereNotNull('account_deleted_at')->update([
                'deleted_at' => null,
                'account_deleted_at' => null,
            ]);
            $user->restore();

            return new RestoreDeletedAccountResult($user, $restoredPosts);
        });
    }
}
