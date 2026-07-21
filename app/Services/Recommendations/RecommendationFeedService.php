<?php

namespace App\Services\Recommendations;

use App\Data\Recommendations\RecommendationFeedPage;
use App\Models\FeatureFlag;
use App\Models\Post;
use App\Models\RecommendationFeedSession;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecommendationFeedService
{
    public function __construct(
        private readonly CandidateGenerationService $candidates,
        private readonly RecommendationRankingService $ranking,
        private readonly CandidateEligibilityService $eligibility,
    ) {}

    public function page(User $viewer, ?string $cursor, int $perPage): RecommendationFeedPage
    {
        if ($cursor === null) {
            $session = $this->createSession($viewer);
            $offset = 0;
        } else {
            [$session, $offset] = $this->resolveCursor($viewer, $cursor);
        }

        if (! $this->servingEnabled()) {
            return new RecommendationFeedPage(collect(), $session->id, $session->request_id, null);
        }

        /** @var list<array<string, mixed>> $items */
        $items = (array) $session->items;
        $slice = array_slice($items, $offset, $perPage);
        $ids = array_map(fn (array $item): int => (int) $item['post_id'], $slice);
        $posts = $this->eligibility->query($viewer)->whereIn('id', $ids)
            ->with(['user', 'media', 'category'])->withCount(['likes', 'comments'])->get()->keyBy('id');

        $ordered = collect($slice)->map(function (array $item) use ($posts, $session): ?Post {
            $post = $posts->get((int) $item['post_id']);
            if (! $post instanceof Post) {
                return null;
            }
            $post->recommendation = [
                'request_id' => $session->request_id,
                'source' => (string) $item['source'],
                'reason' => (string) $item['reason'],
            ];

            return $post;
        })->filter()->values();

        $nextOffset = $offset + count($slice);
        $nextCursor = $nextOffset < count($items)
            ? Crypt::encryptString(json_encode(['session_id' => $session->id, 'offset' => $nextOffset], JSON_THROW_ON_ERROR))
            : null;

        return new RecommendationFeedPage($ordered, $session->id, $session->request_id, $nextCursor);
    }

    private function createSession(User $viewer): RecommendationFeedSession
    {
        RecommendationFeedSession::query()->where('user_id', $viewer->id)->where('expires_at', '<=', now())->delete();
        $ranked = $this->servingEnabled() ? $this->ranking->rank($viewer, $this->candidates->generate($viewer)) : collect();

        return RecommendationFeedSession::create([
            'request_id' => (string) Str::uuid(),
            'user_id' => $viewer->id,
            'ranking_version' => (string) config('social.recommendations.ranking_version', 'v1'),
            'items' => $ranked->map->toArray()->all(),
            'expires_at' => now()->addMinutes((int) config('social.recommendations.feed_session_ttl_minutes', 30)),
        ]);
    }

    /** @return array{RecommendationFeedSession, int} */
    private function resolveCursor(User $viewer, string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! is_string($payload['session_id'] ?? null) || ! is_int($payload['offset'] ?? null)) {
                throw new \RuntimeException('Invalid cursor payload.');
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => ['The recommendation cursor is invalid or expired.']]);
        }

        $session = RecommendationFeedSession::query()->whereKey($payload['session_id'])
            ->where('user_id', $viewer->id)->where('expires_at', '>', now())->first();
        if (! $session || $payload['offset'] < 0 || $payload['offset'] > count((array) $session->items)) {
            throw ValidationException::withMessages(['cursor' => ['The recommendation cursor is invalid or expired.']]);
        }

        return [$session, $payload['offset']];
    }

    private function servingEnabled(): bool
    {
        $control = FeatureFlag::query()->where('key', 'recommendations.serving')->first();

        return $control === null || $control->isActive();
    }
}
