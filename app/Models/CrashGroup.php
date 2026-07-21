<?php

namespace App\Models;

use App\Enums\CrashGroupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string $name
 * @property string|null $exception_class
 * @property CrashGroupStatus $status
 * @property int|null $assigned_to
 * @property string|null $fixed_app_version
 * @property int $occurrence_count
 * @property int $affected_user_count
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $resolved_at
 */
class CrashGroup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CrashGroupStatus::class,
            'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime',
            'occurrence_count' => 'integer', 'affected_user_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<TelemetryEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }

    /** @return HasMany<CrashGroupNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CrashGroupNote::class);
    }
}
