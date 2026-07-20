<?php

namespace App\Models;

use App\Enums\ModerationCasePriority;
use App\Enums\ModerationCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $open_key
 * @property ModerationCaseStatus $status
 * @property ModerationCasePriority $priority
 * @property int|null $assigned_to
 * @property int $report_count
 */
class ModerationCase extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationCaseStatus::Open->value,
        'priority' => ModerationCasePriority::Normal->value,
        'report_count' => 0,
    ];

    protected $fillable = ['target_type', 'target_id', 'open_key', 'status', 'priority', 'assigned_to', 'report_count', 'last_reported_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['status' => ModerationCaseStatus::class, 'priority' => ModerationCasePriority::class, 'last_reported_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return HasMany<ModerationCaseNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ModerationCaseNote::class);
    }
}
