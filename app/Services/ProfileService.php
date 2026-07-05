<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    public function __construct(private readonly ImageProcessingService $images) {}

    /**
     * @param  array{bio?: string|null, avatar?: UploadedFile|null}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        if (array_key_exists('bio', $data)) {
            $user->bio = $data['bio'];
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
