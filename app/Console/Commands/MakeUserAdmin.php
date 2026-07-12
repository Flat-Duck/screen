<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeUserAdmin extends Command
{
    /** @var string */
    protected $signature = 'users:make-admin {email} {--revoke : Remove admin/telemetry-dashboard access instead of granting it}';

    /** @var string */
    protected $description = 'Grants (or revokes) telemetry-dashboard access for a user, by email.';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->is_admin = ! $this->option('revoke');
        $user->save();

        $this->info($this->option('revoke')
            ? "Revoked telemetry-dashboard access from {$user->email}."
            : "Granted telemetry-dashboard access to {$user->email}.");

        return self::SUCCESS;
    }
}
