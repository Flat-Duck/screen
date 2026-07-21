<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountVisibility;
use App\Enums\AdminRole;
use App\Enums\UserModerationState;
use App\Enums\UserVisibilityState;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

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
 * @property AdminRole|null $admin_role
 * @property UserVisibilityState $visibility_state
 * @property UserModerationState $moderation_state
 * @property AccountVisibility $account_visibility
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool|null $is_following Set per-request by ProfileService/UserController for the current viewer — not a DB column.
 * @property bool|null $follows_you Set per-request by UserController for the current viewer — not a DB column.
 * @property string|null $follow_request_status Set per-request by UserController for the current viewer — not a DB column.
 * @property bool|null $is_blocked Set per-request by UserController for the current viewer — not a DB column.
 * @property bool|null $is_blocked_by Set per-request by UserController for the current viewer — not a DB column.
 */
#[Fillable(['name', 'email', 'password', 'username', 'bio', 'avatar_path', 'birth_date', 'country_code'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, Searchable, SoftDeletes, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'account_visibility' => AccountVisibility::Public->value,
    ];

    /** @return array<string, string|null> */
    #[SearchUsingPrefix(['username'])]
    public function toSearchableArray(): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPubliclyVisible();
    }

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
            'admin_role' => AdminRole::class,
            'is_active' => 'boolean',
            'visibility_state' => UserVisibilityState::class,
            'moderation_state' => UserModerationState::class,
            'account_visibility' => AccountVisibility::class,
            'moderated_at' => 'datetime',
        ];
    }

    /** @param Builder<User> $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('visibility_state', UserVisibilityState::Visible->value)
            ->where('moderation_state', UserModerationState::Clear->value);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->is_active
            && $this->visibility_state === UserVisibilityState::Visible
            && $this->moderation_state === UserModerationState::Clear;
    }

    public function hasAdminPermission(string $permission): bool
    {
        if (! $this->is_admin) {
            return false;
        }

        $role = $this->admin_role;

        return ($role ?? AdminRole::SuperAdmin)->can($permission);
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
            ->withPivot(['last_read_at', 'hidden_at'])
            ->withTimestamps();
    }

    /** @return HasMany<FollowRequest, $this> */
    public function outgoingFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'requester_id');
    }

    /** @return HasMany<FollowRequest, $this> */
    public function incomingFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'target_id');
    }

    /** @return HasMany<UserHiddenTerm, $this> */
    public function hiddenTerms(): HasMany
    {
        return $this->hasMany(UserHiddenTerm::class);
    }

    /** @return HasMany<UserRestriction, $this> */
    public function restrictions(): HasMany
    {
        return $this->hasMany(UserRestriction::class);
    }

    /** @return HasMany<UserSupportNote, $this> */
    public function supportNotes(): HasMany
    {
        return $this->hasMany(UserSupportNote::class);
    }

    /** @return MorphMany<AdminAuditLog, $this> */
    public function adminAuditLogsAsTarget(): MorphMany
    {
        return $this->morphMany(AdminAuditLog::class, 'target');
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
