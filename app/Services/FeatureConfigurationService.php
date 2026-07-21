<?php

namespace App\Services;

use App\Models\Experiment;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FeatureConfigurationService
{
    private const ALLOWED_SCOPES = ['product', 'recommendation', 'operations'];

    private const PROTECTED_PREFIXES = ['privacy.', 'moderation.', 'safety.', 'auth.', 'visibility.'];

    public function __construct(private readonly AdminAuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function configureFlag(User $actor, string $key, array $data, string $reason): FeatureFlag
    {
        return DB::transaction(function () use ($actor, $key, $data, $reason): FeatureFlag {
            $this->validateKeyAndScope($key, (string) ($data['scope'] ?? 'product'));
            $rollout = (int) ($data['rollout_basis_points'] ?? 10000);
            if ($rollout < 0 || $rollout > 10000) {
                throw new InvalidArgumentException('Flag rollout must be between 0 and 10,000 basis points.');
            }
            $flag = FeatureFlag::query()->firstOrNew(['key' => $key]);
            $before = $flag->exists ? $this->snapshot($flag) : null;
            $flag->fill([
                ...$data,
                'key' => $key,
                'name' => $data['name'] ?? ($flag->name ?: str($key)->headline()->toString()),
                'rollout_basis_points' => $rollout,
                'version' => $flag->exists ? $flag->version + 1 : 1,
            ])->save();
            $this->audit->record($actor, 'feature_flag.configured', $flag, $reason, $before, $this->snapshot($flag));

            return $flag;
        });
    }

    /** @param array<string, mixed> $data */
    public function configureExperiment(User $actor, string $key, array $data, string $reason): Experiment
    {
        return DB::transaction(function () use ($actor, $key, $data, $reason): Experiment {
            $this->validateKeyAndScope($key, (string) ($data['scope'] ?? 'product'));
            $variants = $data['variants'] ?? null;
            if (! is_array($variants) || ! isset($variants['control'], $variants['treatment'])
                || array_sum($variants) !== 10000
                || collect($variants)->contains(fn ($weight, $variant): bool => ! is_string($variant) || preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $variant) !== 1 || ! is_int($weight) || $weight < 0)) {
                throw new InvalidArgumentException('Experiment variants must include control and treatment and total 10,000 basis points.');
            }
            $allocation = (int) ($data['allocation_basis_points'] ?? 10000);
            if ($allocation < 0 || $allocation > 10000) {
                throw new InvalidArgumentException('Experiment allocation must be between 0 and 10,000 basis points.');
            }
            $experiment = Experiment::query()->firstOrNew(['key' => $key]);
            $before = $experiment->exists ? $this->snapshot($experiment) : null;
            $experiment->fill([
                ...$data,
                'key' => $key,
                'name' => $data['name'] ?? ($experiment->name ?: str($key)->headline()->toString()),
                'variants' => $variants,
                'allocation_basis_points' => $allocation,
                'salt' => $data['salt'] ?? ($experiment->salt ?: hash('sha256', $key)),
                'version' => $experiment->exists ? $experiment->version + 1 : 1,
            ])->save();
            $this->audit->record($actor, 'experiment.configured', $experiment, $reason, $before, $this->snapshot($experiment));

            return $experiment;
        });
    }

    private function validateKeyAndScope(string $key, string $scope): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $key) !== 1 || ! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException('Feature key or scope is invalid.');
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                throw new InvalidArgumentException('Privacy, moderation, safety, authentication, and visibility behavior cannot be experimented on.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $model): array
    {
        return $model->only(['key', 'name', 'description', 'scope', 'is_enabled', 'kill_switch', 'rollout_basis_points', 'allocation_basis_points', 'payload', 'variants', 'version', 'starts_at', 'ends_at']);
    }
}
