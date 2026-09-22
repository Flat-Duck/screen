<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\CompleteSocialLogin;
use App\Actions\Auth\CompleteTwoFactorLogin;
use App\Actions\Auth\PasswordLogin;
use App\Actions\Auth\RegisterUser;
use App\Data\Auth\DeviceSessionContext;
use App\Data\Auth\RegisterUserData;
use App\Http\Requests\FacebookLoginRequest;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Http\Responses\AuthResponseFactory;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\AuthService;
use App\Services\InviteCodeService;
use App\Services\SocialAuth\FacebookTokenVerifier;
use App\Services\SocialAuth\GoogleTokenVerifier;
use App\Services\SocialAuth\SocialUserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthResponseFactory $responses,
        private readonly InviteCodeService $inviteCodes,
    ) {}

    public function register(RegisterUserRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $data = $request->toData();
        $ticket = $request->string('invite_ticket')->toString();
        if ($ticket !== '') {
            $data = new RegisterUserData(
                $data->name,
                $data->username,
                $data->email,
                $data->password,
                $this->resolveTicketOrFail($ticket),
            );
        }

        return $this->responses->make(
            $registerUser($this->device($request), $data, $this->context($request)),
            successStatus: 201,
        );
    }

    public function login(LoginRequest $request, PasswordLogin $passwordLogin): JsonResponse
    {
        $result = $passwordLogin(
            $this->device($request),
            $request->string('login')->toString(),
            $request->string('password')->toString(),
            $this->context($request),
        );

        return $this->responses->make($result);
    }

    public function google(GoogleLoginRequest $request, GoogleTokenVerifier $verifier, CompleteSocialLogin $socialLogin): JsonResponse
    {
        $payload = $verifier->verify($request->string('access_token')->toString());

        return $this->respondToSocialLogin($request, $socialLogin, $payload);
    }

    public function facebook(FacebookLoginRequest $request, FacebookTokenVerifier $verifier, CompleteSocialLogin $socialLogin): JsonResponse
    {
        $payload = $verifier->verify($request->string('access_token')->toString());

        return $this->respondToSocialLogin($request, $socialLogin, $payload);
    }

    /**
     * Completes a login that stopped at `{"requires_two_factor": true, ...}` — the
     * second step of the stateless two-step login (see BeginTwoFactorChallenge).
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request, CompleteTwoFactorLogin $completeLogin): JsonResponse
    {
        $result = $completeLogin(
            $this->device($request),
            $request->string('two_factor_token')->toString(),
            $request->input('code'),
            $request->input('recovery_code'),
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

    private function respondToSocialLogin(Request $request, CompleteSocialLogin $socialLogin, SocialUserPayload $payload): JsonResponse
    {
        $ticket = $request->string('invite_ticket')->toString();
        if ($ticket !== '') {
            $resolvedCode = $this->resolveTicketOrFail($ticket);
        } else {
            $inviteCode = $request->string('invite_code')->toString();
            $resolvedCode = $inviteCode !== '' ? $inviteCode : null;
        }

        $result = $socialLogin($this->device($request), $payload, $this->context($request), $resolvedCode);

        return $this->responses->make(
            $result,
            successStatus: $result instanceof IssuedAccessToken && $result->isNewAccount ? 201 : 200,
            includeIsNewAccount: true,
        );
    }

    /**
     * An `invite_ticket` field takes priority over a raw `invite_code` whenever it's present —
     * see RegisterUserRequest/GoogleLoginRequest/FacebookLoginRequest's matching field kdoc. This
     * throws a *field-specific* `invite_ticket` validation error (distinct from an invalid raw
     * `invite_code`) when the ticket is missing/expired, so the client can tell "your invite
     * session expired, go back to the invite screen" apart from "the code itself was wrong."
     */
    private function resolveTicketOrFail(string $ticket): string
    {
        $code = $this->inviteCodes->resolveTicket($ticket);

        if ($code === null) {
            throw ValidationException::withMessages([
                'invite_ticket' => [__('Your invite session has expired. Please enter your invite code again.')],
            ]);
        }

        return $code;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }

    private function context(Request $request): DeviceSessionContext
    {
        return new DeviceSessionContext(
            deviceName: $request->string('device_name', 'mobile')->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
