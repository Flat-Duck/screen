<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\InviteCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteLinkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_link_creation_requires_an_authenticated_user(): void
    {
        $this->postJson('/api/v1/me/invite-link')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_an_opaque_canonical_invite_link(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $session = $this->startUserSession($user);

        $response = $this->withToken($session->token)
            ->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->postJson('/api/v1/me/invite-link');

        $response->assertCreated();
        $url = $response->json('data.url');
        $this->assertMatchesRegularExpression('#\Ahttps://akukas\.ly/invite/[a-f0-9]{64}\z#', $url);
        $this->assertDatabaseHas('user_invite_links', [
            'inviter_user_id' => $user->id,
            'token_hash' => hash('sha256', basename($url)),
        ]);
        $this->assertDatabaseMissing('user_invite_links', ['token_hash' => basename($url)]);
    }

    public function test_share_link_generation_uses_configured_canonical_host(): void
    {
        config(['social.canonical_url' => 'https://links.akukas.test/']);
        $user = User::factory()->create();

        $this->assertStringStartsWith('https://links.akukas.test/invite/', app(InviteCodeService::class)->createShareLink($user));
    }
}
