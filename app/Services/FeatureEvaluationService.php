<?php

namespace App\Services;

use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class FeatureEvaluationService
{
    /** @return array<string, array{version: int, payload: array<string, mixed>}> */
    public function flagsFor(User $user): array
    {
        $flags = [];
        foreach (FeatureFlag::query()->where('is_enabled', true)->get() as $flag) {
            if ($flag->isActive() && $this->bucket('flag|'.$flag->key.'|'.$user->id) < $flag->rollout_basis_points) {
                $flags[$flag->key] = [
                    'version' => $flag->version,
                    'payload' => $flag->payload ?? [],
                ];
            }
        }

        return $flags;
    }

    /** @return array<string, string> */
    public function assignmentsFor(User $user): array
    {
        $assignments = [];
        foreach (Experiment::query()->where('is_enabled', true)->get() as $experiment) {
            if (! $experiment->isActive()
                || $this->bucket('allocation|'.$experiment->salt.'|'.$user->id) >= $experiment->allocation_basis_points) {
                continue;
            }

            $assignment = ExperimentAssignment::query()->where([
                'experiment_id' => $experiment->id,
                'user_id' => $user->id,
                'experiment_version' => $experiment->version,
            ])->first();
            if (! $assignment) {
                $variant = $this->variant($experiment, $user);
                $assignment = ExperimentAssignment::query()->firstOrCreate(
                    ['experiment_id' => $experiment->id, 'user_id' => $user->id, 'experiment_version' => $experiment->version],
                    ['variant' => $variant, 'assigned_at' => now()],
                );
            }
            $assignments[$experiment->key] = $assignment->variant;
        }

        ksort($assignments);

        return $assignments;
    }

    /** @param array<string, mixed> $reported */
    public function validateReportedAssignments(User $user, array $reported, string $attribute): void
    {
        if ($reported === []) {
            return;
        }

        foreach ($reported as $key => $variant) {
            $valid = is_string($variant)
                && ExperimentAssignment::query()->where('user_id', $user->id)->where('variant', $variant)
                    ->whereHas('experiment', fn ($query) => $query->where('key', $key))->exists();
            if (! $valid) {
                throw ValidationException::withMessages([
                    $attribute => ['Experiment assignments must match assignments issued by the server.'],
                ]);
            }
        }
    }

    private function variant(Experiment $experiment, User $user): string
    {
        $bucket = $this->bucket('variant|'.$experiment->salt.'|'.$user->id);
        $cursor = 0;
        foreach ((array) $experiment->variants as $variant => $weight) {
            $cursor += (int) $weight;
            if ($bucket < $cursor) {
                return (string) $variant;
            }
        }

        return 'control';
    }

    private function bucket(string $input): int
    {
        return hexdec(substr(hash_hmac('sha256', $input, (string) config('app.key')), 0, 7)) % 10000;
    }
}
