<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterUserRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return response()->json([
            'user' => new UserResource($result['user']->loadCount(['posts', 'followers', 'following'])),
            'token' => $result['token'],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            [
                'login' => $request->string('login')->toString(),
                'password' => $request->string('password')->toString(),
            ],
            $request->string('device_name', 'mobile')->toString(),
        );

        return response()->json([
            'user' => new UserResource($result['user']->loadCount(['posts', 'followers', 'following'])),
            'token' => $result['token'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->logout($user);

        return response()->json(null, 204);
    }
}
