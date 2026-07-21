<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Experiment;
use App\Models\User;
use App\Services\FeatureConfigurationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ConfigureExperiment extends Command
{
    protected $signature = 'experiments:configure {key} {actor_email} {--reason=} {--scope=} {--allocation=} {--variants=} {--enable} {--disable} {--kill} {--restore}';

    protected $description = 'Create or update an experiment with an attributed admin audit log.';

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

        try {
            $existing = Experiment::query()->where('key', $this->argument('key'))->first();
            $data = [
                'scope' => $this->option('scope') ?: ($existing !== null ? $existing->scope : 'product'),
                'variants' => $this->option('variants') !== null
                    ? $this->variants((string) $this->option('variants'))
                    : ($existing !== null ? $existing->variants : ['control' => 5000, 'treatment' => 5000]),
            ];
            if ($this->option('allocation') !== null) {
                $data['allocation_basis_points'] = (int) round((float) $this->option('allocation') * 100);
            }
            if ($this->option('enable') || $this->option('disable')) {
                $data['is_enabled'] = (bool) $this->option('enable');
            }
            if ($this->option('kill') || $this->option('restore')) {
                $data['kill_switch'] = (bool) $this->option('kill');
            }

            $experiment = $features->configureExperiment($actor, (string) $this->argument('key'), $data, $reason);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->info("Configured {$experiment->key} v{$experiment->version}.");

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function variants(string $option): array
    {
        $variants = [];
        foreach (explode(',', $option) as $entry) {
            [$variant, $percent] = array_pad(explode(':', $entry, 2), 2, null);
            if ($variant === '' || $percent === null || ! is_numeric($percent)) {
                throw new InvalidArgumentException('Variants use name:percent pairs separated by commas.');
            }
            $variants[$variant] = (int) round((float) $percent * 100);
        }

        return $variants;
    }
}
