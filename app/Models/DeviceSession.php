<?php

namespace App\Models;

use App\Enums\LoginMethod;
use App\Enums\SessionEndReason;
use Database\Factories\DeviceSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $device_id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property LoginMethod $login_method
 * @property SessionEndReason|null $end_reason
 * @property Carbon $started_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $ended_at
 */
class DeviceSession extends Model
{
    /** @use HasFactory<DeviceSessionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'login_method' => LoginMethod::class,
            'end_reason' => SessionEndReason::class,
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'two_factor_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /** @return HasMany<TelemetryEvent, $this> */
    public function telemetryEvents(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }
}
