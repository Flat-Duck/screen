<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MakeUserAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_grants_admin_access_by_email(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com']);

        Artisan::call('users:make-admin', ['email' => 'staff@example.com']);

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_revoke_option_removes_admin_access(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com', 'is_admin' => true]);

        Artisan::call('users:make-admin', ['email' => 'staff@example.com', '--revoke' => true]);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_fails_for_an_unknown_email(): void
    {
        $exitCode = Artisan::call('users:make-admin', ['email' => 'nobody@example.com']);

        $this->assertSame(1, $exitCode);
    }
}
