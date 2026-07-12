<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AppleLoginRequest;
use App\Http\Requests\FacebookLoginRequest;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SocialAuth\AppleTokenVerifier;
use App\Services\SocialAuth\FacebookTokenVerifier;
use App\Services\SocialAuth\GoogleTokenVerifier;
use App\Services\SocialAuth\SocialUserPayload;
use App\Services\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SocialAuthService $socialAuth,
    ) {}

    public function register(RegisterUserRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return response()->json([
            'user' => new UserResource($result['user']->loadCount(['posts', 'followers', 'following'])),
            'token' => $result['token'],
            'profile_completion' => $result['user']->profileCompletionStatus(),
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

        if (isset($result['requires_two_factor'])) {
            return response()->json($result);
        }

        return response()->json([
            'user' => new UserResource($result['user']->loadCount(['posts', 'followers', 'following'])),
            'token' => $result['token'],
            'profile_completion' => $result['user']->profileCompletionStatus(),
        ]);
    }

    public function google(GoogleLoginRequest $request, GoogleTokenVerifier $verifier): JsonResponse
    {
        $payload = $verifier->verify($request->string('id_token')->toString());

        return $this->respondToSocialLogin($payload, $request->string('device_name', 'mobile')->toString());
    }

    public function facebook(FacebookLoginRequest $request, FacebookTokenVerifier $verifier): JsonResponse
    {
        $payload = $verifier->verify($request->string('access_token')->toString());

        return $this->respondToSocialLogin($payload, $request->string('device_name', 'mobile')->toString());
    }

    public function apple(AppleLoginRequest $request, AppleTokenVerifier $verifier): JsonResponse
    {
        $payload = $verifier->verify($request->string('identity_token')->toString());

        // Apple's identity token never carries a name; the client sends it separately,
        // and only on the very first authorization.
        $name = trim(sprintf('%s %s', $request->string('given_name', ''), $request->string('family_name', '')));
        if ($name !== '') {
            $payload = $payload->withName($name);
        }

        return $this->respondToSocialLogin($payload, $request->string('device_name', 'mobile')->toString());
    }

    /**
     * Completes a login that stopped at `{"requires_two_factor": true, ...}` — the
     * second step of the stateless two-step login (see AuthService::twoFactorChallengeResponse()).
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $result = $this->auth->completeTwoFactorChallenge(
            $request->string('two_factor_token')->toString(),
            $request->input('code'),
            $request->input('recovery_code'),
            $request->string('device_name', 'mobile')->toString(),
        );

        return response()->json([
            'user' => new UserResource($result['user']->loadCount(['posts', 'followers', 'following'])),
            'token' => $result['token'],
            'profile_completion' => $result['user']->profileCompletionStatus(),
        ]);
    }

    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->setPassword($user, $request->string('password')->toString());

        return response()->json([
            'profile_completion' => $user->profileCompletionStatus(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->logout($user);

        return response()->json(null, 204);
    }

    private function respondToSocialLogin(SocialUserPayload $payload, string $deviceName): JsonResponse
    {
        $result = $this->socialAuth->loginOrRegister($payload, $deviceName);

        if (isset($result['requires_two_factor'])) {
            return response()->json($result);
        }

        return response()->json([
            'user' => new UserResource($result['user']->loadCount(['posts', 'followers', 'following'])),
            'token' => $result['token'],
            'is_new_account' => $result['is_new_account'],
            'profile_completion' => $result['user']->profileCompletionStatus(),
        ], $result['is_new_account'] ? 201 : 200);
    }
}
