<?php

namespace Tests\Feature\Actions;

use App\Actions\Auth\CompleteTwoFactorLogin;
use App\Actions\Auth\PasswordLogin;
use App\Actions\Auth\RegisterUser;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_user_issues_a_named_token(): void
    {
        $result = app(RegisterUser::class)([
            'name' => 'Ada',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password123!',
            'device_name' => 'pixel',
        ]);

        $this->assertInstanceOf(IssuedAccessToken::class, $result);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'pixel']);
    }

    public function test_password_login_uses_the_shared_token_issuer(): void
    {
        $user = User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $result = app(PasswordLogin::class)('ada', 'password123!', 'tablet');

        $this->assertInstanceOf(IssuedAccessToken::class, $result);
        $this->assertSame($user->id, $result->user->id);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'tablet']);
    }

    public function test_complete_two_factor_login_rejects_an_unknown_challenge(): void
    {
        $this->expectException(ValidationException::class);

        app(CompleteTwoFactorLogin::class)('unknown', '123456', null, 'mobile');
    }
}
