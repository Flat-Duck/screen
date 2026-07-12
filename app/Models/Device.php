<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A single physical device that has installed the app — the API's authenticatable principal
 * (extends the same Authenticatable base the human User model does, so Sanctum's auth:sanctum
 * guard can resolve a Device as $request->user() the same way it would a User; the two never mix
 * because each Sanctum token belongs to exactly one tokenable model).
 */
class Device extends Authenticatable
{
    /** @use HasFactory<DeviceFactory> */
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'device_uuid',
        'manufacturer',
        'brand',
        'model',
        'os_name',
        'os_version',
        'sdk_int',
        'app_version_name',
        'app_version_code',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'sdk_int' => 'integer',
            'app_version_code' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<TelemetryEvent, $this> */
    public function telemetryEvents(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }

    /** @return HasMany<TelemetryEvent, $this> Plain (non-error) app events. */
    public function events(): HasMany
    {
        return $this->telemetryEvents()->where('kind', TelemetryEvent::KIND_EVENT);
    }

    /** @return HasMany<TelemetryEvent, $this> Both non-fatal caught exceptions and fatal uncaught crashes. */
    public function crashes(): HasMany
    {
        return $this->telemetryEvents()->where('kind', '!=', TelemetryEvent::KIND_EVENT);
    }
}
