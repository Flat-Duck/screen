<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\RecommendationFeedSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InterestPreferenceService
{
    /** @param list<int> $interestIds */
    public function replace(User $user, array $interestIds): void
    {
        DB::transaction(function () use ($user, $interestIds): void {
            $source = $user->needsInterestOnboarding() ? 'onboarding' : 'explicit_settings';
            $now = now();
            $pivot = collect($interestIds)->mapWithKeys(fn (int $id): array => [$id => [
                'weight' => 100,
                'source' => $source,
                'selected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);

            $user->interests()->sync($pivot->all());
            $user->forceFill(['interests_completed_at' => $now, 'interests_skipped_at' => null])->saveQuietly();
            RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
        });
    }

    public function skip(User $user): void
    {
        if (! $user->needsInterestOnboarding()) {
            return;
        }

        $user->forceFill(['interests_skipped_at' => now()])->saveQuietly();
        RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
    }

    /** @return array{completed: bool, skipped: bool, needs_selection: bool, minimum_selections: int, maximum_selections: int} */
    public function status(User $user): array
    {
        return [
            'completed' => $user->interests_completed_at !== null,
            'skipped' => $user->interests_skipped_at !== null,
            'needs_selection' => $user->needsInterestOnboarding(),
            'minimum_selections' => 3,
            'maximum_selections' => 10,
        ];
    }

    /** @return Collection<int, Interest> */
    public function catalog(): Collection
    {
        return Interest::query()->active()->with(['categories:id,slug,name', 'hashtags:id,name'])
            ->orderBy('sort_order')->orderBy('name')->get();
    }
}
