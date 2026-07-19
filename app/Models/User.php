<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property string|null $pending_email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property Carbon|null $birth_date
 * @property string|null $country_code
 * @property array<string, mixed>|null $settings
 * @property bool $is_admin
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool|null $is_following Set per-request by ProfileService/UserController for the current viewer — not a DB column.
 * @property bool|null $is_blocked Set per-request by UserController for the current viewer — not a DB column.
 * @property bool|null $is_blocked_by Set per-request by UserController for the current viewer — not a DB column.
 */
#[Fillable(['name', 'email', 'password', 'username', 'bio', 'avatar_path', 'birth_date', 'country_code'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'settings' => 'array',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<Like, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<DeviceSession, $this> */
    public function deviceSessions(): HasMany
    {
        return $this->hasMany(DeviceSession::class);
    }

    /**
     * Users this user follows.
     *
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'followee_id')
            ->withTimestamps();
    }

    /**
     * Users following this user.
     *
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'followee_id', 'follower_id')
            ->withTimestamps();
    }

    /**
     * Users this user has blocked.
     *
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocks', 'blocker_id', 'blocked_id')
            ->withTimestamps();
    }

    /**
     * Users who have blocked this user.
     *
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocks', 'blocked_id', 'blocker_id')
            ->withTimestamps();
    }

    /**
     * Users this user has muted — one-directional, doesn't affect the muted user at all,
     * only filters what the muter sees/gets notified about.
     *
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function mutedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mutes', 'muter_id', 'muted_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Hashtag, $this, Pivot, 'pivot'>
     */
    public function followedHashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'hashtag_user')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Conversation, $this, Pivot, 'pivot'>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? Storage::disk(config('social.media_disk'))->url($this->avatar_path)
            : null;
    }

    /**
     * A social sign-in only ever gives us a name/email/avatar — never a username or
     * password, both of which are otherwise required at registration. This surfaces
     * what's still missing so clients can prompt to finish setup, without forcing it:
     * `is_complete` only tracks `username` since that's the one field the rest of the
     * API assumes is set; password/bio/avatar stay optional indefinitely.
     *
     * @return array{is_complete: bool, has_username: bool, has_password: bool, has_bio: bool, has_avatar: bool}
     */
    public function profileCompletionStatus(): array
    {
        return [
            'is_complete' => $this->username !== null,
            'has_username' => $this->username !== null,
            'has_password' => $this->password !== null,
            'has_bio' => $this->bio !== null,
            'has_avatar' => $this->avatar_path !== null,
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
