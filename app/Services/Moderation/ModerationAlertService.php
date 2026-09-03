<?php

namespace App\Services\Moderation;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertState;
use App\Models\ModerationAlert;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Carbon\CarbonInterface;
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

    /**
     * Closes Info alerts whose condition has not been re-detected since `$cutoff`.
     *
     * Scoped hard on purpose. Only Info, because that is the tripwire's "here is something
     * with reach" signal and the only severity raised for a condition that routinely stops
     * being true on its own; a Warning or Critical always waits for a person. Only while
     * still Open, because an acknowledged alert belongs to the moderator who took it.
     *
     * Expiring is not resolving — the state is distinct so the queue never claims someone
     * reviewed something nobody reviewed. Clearing `open_key` means a post that climbs back
     * into the ranking raises a fresh alert rather than silently reusing the closed one.
     *
     * @return int Number of alerts expired.
     */
    public function expireStaleInfo(CarbonInterface $cutoff): int
    {
        return ModerationAlert::query()
            ->where('state', ModerationAlertState::Open->value)
            ->where('severity', ModerationAlertSeverity::Info->value)
            ->where(fn ($query) => $query->where('last_detected_at', '<', $cutoff)->orWhereNull('last_detected_at'))
            ->update([
                'state' => ModerationAlertState::Expired->value,
                'resolved_at' => now(),
                'open_key' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Sidebar badge — unacknowledged, and Warning or worse.
     *
     * Info deliberately does not badge. The trending tripwire raises an Info alert for every
     * item entering the top-K, which in steady state is a permanent double-digit number: a
     * badge that always reads 27 on a healthy app tells a moderator nothing, and trains them
     * to ignore the one time it means something. Info alerts are still in the queue to browse.
     */
    public function openCount(): int
    {
        return ModerationAlert::query()
            ->where('state', ModerationAlertState::Open->value)
            ->whereIn('severity', [ModerationAlertSeverity::Warning->value, ModerationAlertSeverity::Critical->value])
            ->count();
    }
}
