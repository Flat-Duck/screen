<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\CompleteTwoFactorLogin;
use App\Actions\Auth\PasswordLogin;
use App\Actions\Auth\RegisterUser;
use App\Http\Requests\AppleLoginRequest;
use App\Http\Requests\FacebookLoginRequest;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\Auth\TwoFactorRequired;
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

    public function register(RegisterUserRequest $request, RegisterUser $registerUser): JsonResponse
    {
        return $this->respondToAuthResult($registerUser($request->validated()), successStatus: 201);
    }

    public function login(LoginRequest $request, PasswordLogin $passwordLogin): JsonResponse
    {
        $result = $passwordLogin(
            $request->string('login')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name', 'mobile')->toString(),
        );

        return $this->respondToAuthResult($result);
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
     * second step of the stateless two-step login (see BeginTwoFactorChallenge).
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request, CompleteTwoFactorLogin $completeLogin): JsonResponse
    {
        $result = $completeLogin(
            $request->string('two_factor_token')->toString(),
            $request->input('code'),
            $request->input('recovery_code'),
            $request->string('device_name', 'mobile')->toString(),
        );

        return $this->respondToAuthResult($result);
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

        return $this->respondToAuthResult(
            $result,
            successStatus: $result instanceof IssuedAccessToken && $result->isNewAccount ? 201 : 200,
            includeIsNewAccount: true,
        );
    }

    /**
     * Shared response shape for every endpoint that can end in either a fresh token or
     * a two-factor challenge — avoids re-deriving the same `{user, token,
     * profile_completion}` (or `{requires_two_factor, two_factor_token}`) JSON at each
     * of the four call sites above. `includeIsNewAccount` exists because only social
     * login's response ever carried that field — register/login/2FA-challenge never
     * did, and shouldn't start just because they now share this DTO.
     */
    private function respondToAuthResult(
        IssuedAccessToken|TwoFactorRequired $result,
        int $successStatus = 200,
        bool $includeIsNewAccount = false,
    ): JsonResponse {
        if (! $result instanceof IssuedAccessToken) {
            return response()->json([
                'requires_two_factor' => true,
                'two_factor_token' => $result->twoFactorToken,
            ]);
        }

        return response()->json([
            'user' => new UserResource($result->user->loadCount(['posts', 'followers', 'following'])),
            'token' => $result->token,
            ...($includeIsNewAccount ? ['is_new_account' => $result->isNewAccount] : []),
            'profile_completion' => $result->user->profileCompletionStatus(),
        ], $successStatus);
    }
}
