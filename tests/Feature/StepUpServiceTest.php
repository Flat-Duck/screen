<?php

namespace Tests\Feature;

use App\Mail\AccountConfirmationCodeMail;
use App\Models\User;
use App\Services\StepUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class StepUpServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TOTP codes are deterministic per 30-second window — reusing the exact same code
     * twice within a test (once to confirm setup, again to verify a step-up) risks
     * hitting replay protection. $window picks a distinct-but-still-in-tolerance code.
     */
    private function codeAt(string $secret, int $window): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $window);
    }

    public function test_required_method_is_password_when_a_password_is_set(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $this->assertSame('password', app(StepUpService::class)->requiredMethod($user));
    }

    public function test_required_method_is_two_factor_for_a_passwordless_2fa_account(): void
    {
        $user = User::factory()->create(['password' => null]);
        app(EnableTwoFactorAuthentication::class)($user);
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $this->codeAt($secret, 0));

        $this->assertSame('two_factor', app(StepUpService::class)->requiredMethod($user->fresh()));
    }

    public function test_required_method_is_email_code_for_a_passwordless_non_2fa_account(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->assertSame('email_code', app(StepUpService::class)->requiredMethod($user));
    }

    public function test_verify_accepts_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        app(StepUpService::class)->verify($user, ['current_password' => 'password123!']);
        $this->addToAssertionCount(1);
    }

    public function test_verify_rejects_the_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);

        $this->expectException(ValidationException::class);
        app(StepUpService::class)->verify($user, ['current_password' => 'wrong']);
    }

    public function test_verify_accepts_a_valid_totp_code_for_a_passwordless_2fa_account(): void
    {
        $user = User::factory()->create(['password' => null]);
        app(EnableTwoFactorAuthentication::class)($user);
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $this->codeAt($secret, 0));
        $user = $user->fresh();

        app(StepUpService::class)->verify($user, ['two_factor_code' => $this->codeAt($secret, 1)]);
        $this->addToAssertionCount(1);
    }

    public function test_verify_rejects_a_current_password_field_for_a_passwordless_account(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->expectException(ValidationException::class);
        app(StepUpService::class)->verify($user, ['current_password' => 'anything']);
    }

    public function test_email_code_round_trip(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => null]);

        app(StepUpService::class)->sendEmailCode($user);

        $code = null;
        Mail::assertQueued(AccountConfirmationCodeMail::class, function (AccountConfirmationCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        app(StepUpService::class)->verify($user, ['confirmation_code' => $code]);
        $this->addToAssertionCount(1);
    }

    public function test_email_code_is_single_use(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => null]);

        app(StepUpService::class)->sendEmailCode($user);
        $code = null;
        Mail::assertQueued(AccountConfirmationCodeMail::class, function (AccountConfirmationCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        app(StepUpService::class)->verify($user, ['confirmation_code' => $code]);

        $this->expectException(ValidationException::class);
        app(StepUpService::class)->verify($user, ['confirmation_code' => $code]);
    }

    public function test_email_code_rejects_a_wrong_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => null]);
        app(StepUpService::class)->sendEmailCode($user);

        $this->expectException(ValidationException::class);
        app(StepUpService::class)->verify($user, ['confirmation_code' => '000000']);
    }

    /**
     * Simulates the concurrent case directly (a single-threaded test can't produce a
     * true race): another process holding the per-user email-code lock when this
     * request arrives is exactly what a real concurrent double-submit would look like.
     * See StepUpService::verifyEmailCode()'s doc comment.
     */
    public function test_email_code_verification_while_another_request_holds_its_lock_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => null]);
        app(StepUpService::class)->sendEmailCode($user);
        $code = null;
        Mail::assertQueued(AccountConfirmationCodeMail::class, function (AccountConfirmationCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $lock = Cache::lock("step-up-email-code:{$user->id}:lock", 10);
        $lock->get();

        try {
            $this->expectException(ValidationException::class);
            app(StepUpService::class)->verify($user, ['confirmation_code' => $code]);
        } finally {
            $lock->release();
        }
    }
}
