<?php

namespace App\Services\Moderation;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertType;
use App\Models\ModerationCase;
use Illuminate\Database\Eloquent\Model;

/**
 * What a detector emits. Deliberately inert — a draft describes a condition; only
 * ModerationAlertService turns it into a persisted alert, and nothing turns it into a
 * consequence.
 */
class AlertDraft
{
    /**
     * @param  string  $dedupeKey  Stable identity for the condition (not hashed — the service
     *                             hashes it into `open_key`). Two runs detecting the same
     *                             condition must produce the same key, or the queue fills
     *                             with duplicates.
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly ModerationAlertType $type,
        public readonly ModerationAlertSeverity $severity,
        public readonly string $title,
        public readonly string $dedupeKey,
        public readonly ?Model $target = null,
        public readonly array $context = [],
        public readonly ?ModerationCase $moderationCase = null,
    ) {}
}
