<?php

namespace App\Services\Moderation;

use App\Enums\ModerationAlertState;
use App\Models\ModerationAlert;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModerationAlertService
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    /**
     * Idempotent per condition: re-detecting something already alerted refreshes it rather
     * than stacking a duplicate. Severity ratchets up only — a spike that reaches Critical
     * stays Critical for the life of the alert even if the rate later falls back, so a
     * moderator arriving late still sees how bad it got.
     */
    public function raise(AlertDraft $draft): ModerationAlert
    {
        $openKey = hash('sha256', $draft->type->value.':'.$draft->dedupeKey);

        return DB::transaction(function () use ($draft, $openKey): ModerationAlert {
            $alert = ModerationAlert::query()->where('open_key', $openKey)->first();

            if ($alert === null) {
                return ModerationAlert::query()->create([
                    'type' => $draft->type,
                    'severity' => $draft->severity,
                    'state' => ModerationAlertState::Open,
                    'target_type' => $draft->target?->getMorphClass(),
                    'target_id' => $draft->target?->getKey(),
                    'open_key' => $openKey,
                    'title' => $draft->title,
                    'context' => $draft->context,
                    'moderation_case_id' => $draft->moderationCase?->getKey(),
                    'last_detected_at' => now(),
                ]);
            }

            $alert->update([
                'severity' => $draft->severity->weight() < $alert->severity->weight() ? $draft->severity : $alert->severity,
                'title' => $draft->title,
                'context' => $draft->context,
                'moderation_case_id' => $draft->moderationCase?->getKey() ?? $alert->moderation_case_id,
                'last_detected_at' => now(),
            ]);

            return $alert;
        });
    }

    /** Seen and being worked. Drops off the sidebar badge but stays in the queue. */
    public function acknowledge(ModerationAlert $alert, User $actor): void
    {
        if ($alert->state !== ModerationAlertState::Open) {
            throw ValidationException::withMessages(['state' => 'Only an open alert can be acknowledged.']);
        }

        $before = $alert->only(['state', 'acknowledged_by', 'acknowledged_at']);
        $alert->update([
            'state' => ModerationAlertState::Acknowledged,
            'acknowledged_by' => $actor->getKey(),
            'acknowledged_at' => now(),
        ]);
        $this->audit->record($actor, 'moderation_alert.acknowledged', $alert, null, $before, $alert->only(array_keys($before)));
    }

    /**
     * Clears `open_key` on the way out — same nulled-on-resolve pattern as ModerationCase,
     * and the reason the same condition can legitimately alert again tomorrow.
     */
    public function resolve(ModerationAlert $alert, User $actor, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'A resolution reason is required.']);
        }

        $before = $alert->only(['state', 'resolved_at', 'open_key']);
        $alert->update([
            'state' => ModerationAlertState::Resolved,
            'resolved_at' => now(),
            'open_key' => null,
        ]);
        $this->audit->record($actor, 'moderation_alert.resolved', $alert, $reason, $before, $alert->only(array_keys($before)));
    }

    /** Sidebar badge — unacknowledged only, so a queue being actively worked reads as quiet. */
    public function openCount(): int
    {
        return ModerationAlert::query()->where('state', ModerationAlertState::Open->value)->count();
    }
}
