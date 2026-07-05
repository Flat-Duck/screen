<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @param  array<string, string>  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $token = $user->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Matches `login` against email OR username.
     *
     * @param  array<string, string>  $credentials  Expects 'login' and 'password' keys.
     * @return array{user: User, token: string}
     */
    public function login(array $credentials, string $deviceName = 'mobile'): array
    {
        $user = User::query()
            ->where('email', $credentials['login'])
            ->orWhere('username', $credentials['login'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        $token = $user->createToken($deviceName)->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /** Revokes only the current token — a login on another device stays valid. */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
