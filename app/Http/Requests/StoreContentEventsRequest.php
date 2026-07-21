<?php

namespace App\Http\Requests;

use App\Enums\CandidateSource;
use App\Enums\ContentEventType;
use App\Enums\ContentSurface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContentEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*' => ['required', 'array:event_id,event_type,post_id,author_id,surface,position,candidate_source,request_id,experiment_assignments,occurred_at,metadata'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.event_type' => ['required', Rule::enum(ContentEventType::class)],
            'events.*.post_id' => ['nullable', 'integer'],
            'events.*.author_id' => ['required', 'integer'],
            'events.*.surface' => ['required', Rule::enum(ContentSurface::class)],
            'events.*.position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'events.*.candidate_source' => ['nullable', Rule::enum(CandidateSource::class)],
            'events.*.request_id' => ['nullable', 'uuid'],
            'events.*.experiment_assignments' => ['nullable', 'array', 'max:20'],
            'events.*.experiment_assignments.*' => ['string', 'max:50'],
            'events.*.occurred_at' => ['required', 'date', 'after_or_equal:'.now()->subDays(30)->toIso8601String(), 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'events.*.metadata' => ['nullable', 'array:duration_ms,media_position,direction,share_channel,reason'],
            'events.*.metadata.duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'events.*.metadata.media_position' => ['nullable', 'integer', 'min:0', 'max:9'],
            'events.*.metadata.direction' => ['nullable', Rule::in(['next', 'previous'])],
            'events.*.metadata.share_channel' => ['nullable', Rule::in(['system', 'copy_link', 'external'])],
            'events.*.metadata.reason' => ['nullable', Rule::in(['not_relevant', 'seen_before', 'low_quality', 'sensitive', 'other'])],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('events', []) as $index => $event) {
                if (! is_array($event) || ! isset($event['event_type'])) {
                    continue;
                }

                $type = ContentEventType::tryFrom((string) $event['event_type']);
                $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
                $allowedMetadata = match ($type) {
                    ContentEventType::Dwell => ['duration_ms'],
                    ContentEventType::CarouselSwipe => ['media_position', 'direction'],
                    ContentEventType::Zoom => ['media_position'],
                    ContentEventType::Share => ['share_channel'],
                    ContentEventType::Hide, ContentEventType::NotInterested => ['reason'],
                    default => [],
                };
                if (array_diff(array_keys($metadata), $allowedMetadata) !== []) {
                    $validator->errors()->add("events.{$index}.metadata", 'Metadata contains fields not allowed for this event type.');
                }
                if ($type?->requiresPost() === true && ! isset($event['post_id'])) {
                    $validator->errors()->add("events.{$index}.post_id", 'A post is required for this event type.');
                }
                if ($type?->requiresPost() === false && isset($event['post_id'])) {
                    $validator->errors()->add("events.{$index}.post_id", 'A post is not allowed for this event type.');
                }
                if ($type === ContentEventType::Dwell && ! isset($metadata['duration_ms'])) {
                    $validator->errors()->add("events.{$index}.metadata.duration_ms", 'Duration is required for dwell events.');
                }
                if (in_array($type, [ContentEventType::CarouselSwipe, ContentEventType::Zoom], true)
                    && ! isset($metadata['media_position'])) {
                    $validator->errors()->add("events.{$index}.metadata.media_position", 'Media position is required for this event type.');
                }
                if ($type === ContentEventType::CarouselSwipe && ! isset($metadata['direction'])) {
                    $validator->errors()->add("events.{$index}.metadata.direction", 'Direction is required for carousel swipe events.');
                }
                if ($type === ContentEventType::Share && ! isset($metadata['share_channel'])) {
                    $validator->errors()->add("events.{$index}.metadata.share_channel", 'Share channel is required for share events.');
                }
                if (in_array($type, [ContentEventType::Hide, ContentEventType::NotInterested], true)
                    && ! isset($metadata['reason'])) {
                    $validator->errors()->add("events.{$index}.metadata.reason", 'A reason is required for negative-feedback events.');
                }

                foreach (array_keys((array) ($event['experiment_assignments'] ?? [])) as $key) {
                    if (! is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{0,49}$/', $key) !== 1) {
                        $validator->errors()->add("events.{$index}.experiment_assignments", 'Experiment keys must be stable lowercase identifiers.');
                        break;
                    }
                }
            }
        }];
    }
}
