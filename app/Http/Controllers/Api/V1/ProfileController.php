<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ProfileService;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function update(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $user = $this->profiles->updateProfile($user, $request->validated());

        return (new UserResource($user->loadCount(['posts', 'followers', 'following'])))
            ->additional(['profile_completion' => $user->profileCompletionStatus()]);
    }
}
