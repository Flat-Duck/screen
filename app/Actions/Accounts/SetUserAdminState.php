<?php

namespace App\Actions\Accounts;

use App\Enums\AdminRole;
use App\Models\User;

/**
 * Grants or revokes telemetry-dashboard access. `is_admin` is deliberately absent from
 * `User`'s `#[Fillable]` attribute (never mass-assignable) — this Action, used by both
 * `php artisan users:make-admin` and the dashboard's own user-management page, is the
 * only place that ever sets it.
 */
final class SetUserAdminState
{
    public function __invoke(User $user, bool $isAdmin): void
    {
        if ($user->is_admin === $isAdmin) {
            return;
        }

        $user->forceFill([
            'is_admin' => $isAdmin,
            'admin_role' => $isAdmin ? AdminRole::SuperAdmin : null,
        ]);
        $user->save();
    }
}
