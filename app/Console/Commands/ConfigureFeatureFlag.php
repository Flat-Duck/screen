<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\FeatureConfigurationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ConfigureFeatureFlag extends Command
{
    protected $signature = 'features:configure {key} {actor_email} {--reason=} {--scope=} {--rollout=} {--enable} {--disable} {--kill} {--restore}';

    protected $description = 'Create or update a feature flag with an attributed admin audit log.';

    public function handle(FeatureConfigurationService $features): int
    {
        $actor = User::query()->where('email', $this->argument('actor_email'))->first();
        if (! $actor || $actor->admin_role !== AdminRole::SuperAdmin) {
            $this->error('The actor must be a super administrator.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required.');

            return self::INVALID;
        }
        if (($this->option('enable') && $this->option('disable')) || ($this->option('kill') && $this->option('restore'))) {
            $this->error('Enable/disable and kill/restore options are mutually exclusive.');

            return self::INVALID;
        }

        $existing = FeatureFlag::query()->where('key', $this->argument('key'))->first();
        $data = ['scope' => $this->option('scope') ?: ($existing !== null ? $existing->scope : 'product')];
        if ($this->option('rollout') !== null) {
            $data['rollout_basis_points'] = (int) round((float) $this->option('rollout') * 100);
        }
        if ($this->option('enable') || $this->option('disable')) {
            $data['is_enabled'] = (bool) $this->option('enable');
        }
        if ($this->option('kill') || $this->option('restore')) {
            $data['kill_switch'] = (bool) $this->option('kill');
        }

        try {
            $flag = $features->configureFlag($actor, (string) $this->argument('key'), $data, $reason);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->info("Configured {$flag->key} v{$flag->version}.");

        return self::SUCCESS;
    }
}
