<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserInviteLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserInviteLink> */
class UserInviteLinkFactory extends Factory
{
    protected $model = UserInviteLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'inviter_user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
        ];
    }
}
