<?php

namespace App\Models;

use App\Enums\TelemetryKind;
use Database\Factories\TelemetryEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per event/error/crash reported by a device. Error-specific columns are nullable and only
 * populated when kind != 'event' — they're 1:1 with the row, not a separate has-many relation.
 */
class TelemetryEvent extends Model
{
    /** @use HasFactory<TelemetryEventFactory> */
    use HasFactory;

    public const KIND_EVENT = 'event';

    public const KIND_ERROR = 'error';

    public const KIND_FATAL_CRASH = 'fatal_crash';

    public const KINDS = [self::KIND_EVENT, self::KIND_ERROR, self::KIND_FATAL_CRASH];

    protected $fillable = [
        'device_id',
        'user_id',
        'device_session_id',
        'event_uuid',
        'kind',
        'name',
        'occurred_at',
        'received_at',
        'extras',
        'breadcrumbs',
        'error_tag',
        'exception_class',
        'error_message',
        'stack_trace',
        'thread_name',
        'is_fatal',
        'app_version_name',
        'app_version_code',
        'build_type',
        'os_version',
        'crash_fingerprint',
        'crash_group_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'extras' => 'array',
            'breadcrumbs' => 'array',
            'is_fatal' => 'boolean',
            'app_version_code' => 'integer',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DeviceSession, $this> */
    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class);
    }

    /** @return BelongsTo<CrashGroup, $this> */
    public function crashGroup(): BelongsTo
    {
        return $this->belongsTo(CrashGroup::class);
    }

    /**
     * @param  Builder<TelemetryEvent>  $query
     * @return Builder<TelemetryEvent>
     */
    public function scopeCrashes(Builder $query): Builder
    {
        return $query->where('kind', '!=', TelemetryKind::Event->value);
    }
}
