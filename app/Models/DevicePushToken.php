<?php

namespace App\Models;

use Database\Factories\DevicePushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An FCM registration token for one signed-in device. `fcm_token` is globally unique —
 * registering the same token again (token resurfacing after a re-install/account switch)
 * re-points `user_id` rather than creating a second row (see PushTokenService::register()).
 *
 * @property int $id
 * @property int $user_id
 * @property string $fcm_token
 * @property string $platform
 */
class DevicePushToken extends Model
{
    /** @use HasFactory<DevicePushTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fcm_token',
        'platform',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
