<?php

namespace App\Actions\Accounts;

use App\Models\User;

final readonly class RestoreDeletedAccountResult
{
    public function __construct(
        public User $user,
        public int $restoredPosts,
    ) {}
}
