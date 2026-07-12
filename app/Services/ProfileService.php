<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileService
{
    public function __construct(private readonly ImageProcessingService $images) {}

    /**
     * @param  array{username?: string, bio?: string|null, avatar?: UploadedFile|null, birth_date?: string|null, country_code?: string|null}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        if (array_key_exists('username', $data)) {
            $user->username = $data['username'];
        }

        if (array_key_exists('bio', $data)) {
            $user->bio = $data['bio'];
        }

        if (array_key_exists('birth_date', $data)) {
            // $user->birth_date is cast to the mutable Illuminate\Support\Carbon, not a
            // plain string — Carbon::parse() matches the cast's declared type.
            $user->birth_date = $data['birth_date'] === null ? null : Carbon::parse($data['birth_date']);
        }

        if (array_key_exists('country_code', $data)) {
            $user->country_code = $data['country_code'] === null ? null : Str::upper($data['country_code']);
        }

        if (! empty($data['avatar'])) {
            $previousAvatarPath = $user->avatar_path;

            $stored = $this->images->storeOriginal($data['avatar'], "avatars/{$user->id}", maxDimension: 512);
            $user->avatar_path = $stored['path'];

            if ($previousAvatarPath) {
                Storage::disk(config('social.media_disk'))->delete($previousAvatarPath);
            }
        }

        $user->save();

        return $user;
    }

    public function getPublicProfile(User $target): User
    {
        return $target->loadCount(['posts', 'followers', 'following']);
    }
}
