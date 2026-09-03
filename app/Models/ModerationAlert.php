<?php

namespace App\Models;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertState;
use App\Enums\ModerationAlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A detected condition a moderator should look at. Deliberately never carries a consequence
 * of its own — detection raises alerts, humans act on them (see ModerationCaseService for the
 * action side). See App\Services\Moderation\Detectors for what produces these.
 *
 * @property int $id
 * @property ModerationAlertType $type
 * @property ModerationAlertSeverity $severity
 * @property ModerationAlertState $state
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $open_key
 * @property string $title
 * @property array<string, mixed>|null $context
 * @property int|null $moderation_case_id
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $last_detected_at
 * @property Carbon $created_at
 */
class ModerationAlert extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => ModerationAlertState::Open->value,
        'severity' => ModerationAlertSeverity::Warning->value,
    ];

    /** @var list<string> */
    protected $fillable = [
        'type', 'severity', 'state', 'target_type', 'target_id', 'open_key',
        'title', 'context', 'moderation_case_id', 'acknowledged_by',
        'acknowledged_at', 'resolved_at', 'last_detected_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ModerationAlertType::class,
            'severity' => ModerationAlertSeverity::class,
            'state' => ModerationAlertState::class,
            'context' => 'array',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_detected_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<ModerationCase, $this> */
    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Unresolved — both Open and Acknowledged. This is the working queue; the sidebar badge
     * counts only Open (see ModerationAlertService::openCount()).
     *
     * @param  Builder<ModerationAlert>  $query
     * @return Builder<ModerationAlert>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('state', [ModerationAlertState::Open->value, ModerationAlertState::Acknowledged->value]);
    }
}
