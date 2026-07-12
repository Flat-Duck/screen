<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\CompleteSocialLogin;
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
use App\Http\Responses\AuthResponseFactory;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\AuthService;
use App\Services\SocialAuth\AppleTokenVerifier;
use App\Services\SocialAuth\FacebookTokenVerifier;
use App\Services\SocialAuth\GoogleTokenVerifier;
use App\Services\SocialAuth\SocialUserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthResponseFactory $responses,
    ) {}

    public function register(RegisterUserRequest $request, RegisterUser $registerUser): JsonResponse
    {
        return $this->responses->make($registerUser($request->validated()), successStatus: 201);
    }

    public function login(LoginRequest $request, PasswordLogin $passwordLogin): JsonResponse
    {
        $result = $passwordLogin(
            $request->string('login')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name', 'mobile')->toString(),
        );

        return $this->responses->make($result);
    }

    public function google(GoogleLoginRequest $request, GoogleTokenVerifier $verifier, CompleteSocialLogin $socialLogin): JsonResponse
    {
        $payload = $verifier->verify($request->string('id_token')->toString());

        return $this->respondToSocialLogin($socialLogin, $payload, $request->string('device_name', 'mobile')->toString());
    }

    public function facebook(FacebookLoginRequest $request, FacebookTokenVerifier $verifier, CompleteSocialLogin $socialLogin): JsonResponse
    {
        $payload = $verifier->verify($request->string('access_token')->toString());

        return $this->respondToSocialLogin($socialLogin, $payload, $request->string('device_name', 'mobile')->toString());
    }

    public function apple(AppleLoginRequest $request, AppleTokenVerifier $verifier, CompleteSocialLogin $socialLogin): JsonResponse
    {
        $payload = $verifier->verify($request->string('identity_token')->toString());

        // Apple's identity token never carries a name; the client sends it separately,
        // and only on the very first authorization.
        $name = trim(sprintf('%s %s', $request->string('given_name', ''), $request->string('family_name', '')));
        if ($name !== '') {
            $payload = $payload->withName($name);
        }

        return $this->respondToSocialLogin($socialLogin, $payload, $request->string('device_name', 'mobile')->toString());
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

        return $this->responses->make($result);
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

    private function respondToSocialLogin(CompleteSocialLogin $socialLogin, SocialUserPayload $payload, string $deviceName): JsonResponse
    {
        $result = $socialLogin($payload, $deviceName);

        return $this->responses->make(
            $result,
            successStatus: $result instanceof IssuedAccessToken && $result->isNewAccount ? 201 : 200,
            includeIsNewAccount: true,
        );
    }
}
