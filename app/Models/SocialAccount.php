<?php

namespace App\Models;

use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a `User` to an identity on an external OAuth provider (Google/Facebook/Apple).
 * One user can have several rows here (one per linked provider); a provider+provider_user_id
 * pair is globally unique, so a given Google/Facebook/Apple account can only ever map to one
 * `User`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $avatar_url
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_FACEBOOK = 'facebook';

    public const PROVIDER_APPLE = 'apple';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'avatar_url',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
