<?php

namespace App\Actions\Analytics;

use App\Enums\ContentEventType;
use App\Models\ContentEvent;
use App\Models\DeviceSession;
use App\Models\Post;
use App\Models\User;
use App\Services\BlockService;
use App\Services\FeatureEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IngestContentEvents
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly FeatureEvaluationService $features,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    public function __invoke(User $user, DeviceSession $session, array $events): array
    {
        return DB::transaction(function () use ($user, $session, $events): array {
            $accepted = [];

            foreach ($events as $index => $data) {
                $eventUuid = (string) $data['event_id'];
                $reportedAssignments = is_array($data['experiment_assignments'] ?? null)
                    ? $data['experiment_assignments']
                    : [];
                $this->features->validateReportedAssignments($user, $reportedAssignments, "events.{$index}.experiment_assignments");
                $existing = ContentEvent::query()->where('event_uuid', $eventUuid)->first();
                if ($existing) {
                    if ($existing->user_id !== $user->id || $existing->device_id !== $session->device_id) {
                        throw ValidationException::withMessages([
                            "events.{$index}.event_id" => ['This event identifier is already in use.'],
                        ]);
                    }
                    $accepted[] = $eventUuid;

                    continue;
                }

                $type = ContentEventType::from((string) $data['event_type']);
                $occurredAt = CarbonImmutable::parse((string) $data['occurred_at']);
                if ($occurredAt->lt($session->started_at)) {
                    throw ValidationException::withMessages([
                        "events.{$index}.occurred_at" => ['The event occurred before the authenticated device session began.'],
                    ]);
                }

                $authorId = (int) $data['author_id'];
                $postId = isset($data['post_id']) ? (int) $data['post_id'] : null;
                if ($type->requiresPost()) {
                    $post = Post::query()->with('user')->visibleTo($user)->find($postId);
                    if (! $post || $this->blocks->isBlockedEitherWay($user, $post->user)) {
                        throw ValidationException::withMessages([
                            "events.{$index}.post_id" => ['The selected post is unavailable.'],
                        ]);
                    }
                    if ($post->user_id !== $authorId) {
                        throw ValidationException::withMessages([
                            "events.{$index}.author_id" => ['The author does not own the selected post.'],
                        ]);
                    }
                    $authorId = $post->user_id;
                } else {
                    $author = User::query()->publiclyVisible()->find($authorId);
                    if (! $author || $this->blocks->isBlockedEitherWay($user, $author)) {
                        throw ValidationException::withMessages([
                            "events.{$index}.author_id" => ['The selected author is unavailable.'],
                        ]);
                    }
                }

                ContentEvent::create([
                    'event_uuid' => $eventUuid,
                    'user_id' => $user->id,
                    'device_id' => $session->device_id,
                    'device_session_id' => $session->id,
                    'post_id' => $postId,
                    'author_id' => $authorId,
                    'surface' => $data['surface'],
                    'event_type' => $type,
                    'position' => $data['position'] ?? null,
                    'candidate_source' => $data['candidate_source'] ?? null,
                    'request_id' => $data['request_id'] ?? null,
                    'experiment_assignments' => $reportedAssignments === [] ? null : $reportedAssignments,
                    'metadata' => $data['metadata'] ?? null,
                    'occurred_at' => $occurredAt,
                    'received_at' => now(),
                ]);
                $accepted[] = $eventUuid;
            }

            return $accepted;
        });
    }
}
