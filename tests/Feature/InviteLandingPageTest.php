<?php

namespace Tests\Feature;

use Tests\TestCase;

class InviteLandingPageTest extends TestCase
{
    public function test_it_shows_the_code_and_a_play_store_link_carrying_it_as_the_referrer(): void
    {
        $response = $this->get('/invite/ABC123');

        $response->assertOk();
        $response->assertSee('ABC123');
        $response->assertSee('play.google.com', escape: false);
        $response->assertSee('referrer=invite_code%3DABC123', escape: false);
    }

    public function test_opaque_invite_tokens_are_carried_through_the_play_store_referrer(): void
    {
        $token = str_repeat('a', 64);

        $this->get('/invite/'.$token)
            ->assertOk()
            ->assertSee('referrer=invite_token%3D'.$token, escape: false)
            ->assertDontSee('invite code');
    }

    public function test_post_and_profile_links_have_a_non_disclosing_play_store_fallback(): void
    {
        $this->get('/posts/123')->assertOk()->assertSee('Get the app')->assertSee('play.google.com', escape: false);
        $this->get('/profile/456')->assertOk()->assertSee('Get the app')->assertSee('play.google.com', escape: false);
        $this->get('/posts/0')->assertNotFound();
    }
}
