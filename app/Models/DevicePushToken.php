<?php

namespace App\Models;

use Database\Factories\DevicePushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current FCM registration token for one app installation.
 *
 * @property int $id
 * @property int $device_id
 * @property string $fcm_token
 * @property string $platform
 */
class DevicePushToken extends Model
{
    /** @use HasFactory<DevicePushTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'fcm_token',
        'platform',
    ];

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
