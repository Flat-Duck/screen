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
}
