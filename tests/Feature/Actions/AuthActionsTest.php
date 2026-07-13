<?php

namespace Tests\Feature\Actions;

use App\Actions\Auth\CompleteTwoFactorLogin;
use App\Actions\Auth\PasswordLogin;
use App\Actions\Auth\RegisterUser;
use App\Data\Auth\DeviceSessionContext;
use App\Data\Auth\RegisterUserData;
use App\Models\Device;
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
        $device = Device::factory()->create();
        $result = app(RegisterUser::class)(
            $device,
            new RegisterUserData('Ada', 'ada', 'ada@example.com', 'password123!'),
            new DeviceSessionContext('pixel', '127.0.0.1', 'phpunit'),
        );

        $this->assertInstanceOf(IssuedAccessToken::class, $result);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'pixel']);
        $this->assertSame($result->user->id, $device->fresh()->user_id);
    }

    public function test_password_login_uses_the_shared_token_issuer(): void
    {
        $user = User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $device = Device::factory()->create();
        $result = app(PasswordLogin::class)(
            $device,
            'ada',
            'password123!',
            new DeviceSessionContext('tablet', '127.0.0.1', 'phpunit'),
        );

        $this->assertInstanceOf(IssuedAccessToken::class, $result);
        $this->assertSame($user->id, $result->user->id);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'tablet']);
    }

    public function test_complete_two_factor_login_rejects_an_unknown_challenge(): void
    {
        $this->expectException(ValidationException::class);

        app(CompleteTwoFactorLogin::class)(Device::factory()->create(), 'unknown', '123456', null);
    }
}
