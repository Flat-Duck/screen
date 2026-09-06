<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The front page is the one URL a Play reviewer, a link previewer and a first-time visitor all
 * hit, and it is served to signed-out traffic — so the things worth pinning are that it stays
 * public, that it says the product's name rather than the repository's, and that a CTA never
 * points somewhere that does not exist yet.
 */
class LandingPageTest extends TestCase
{
    public function test_it_is_publicly_reachable_and_carries_the_product_name(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Akukas')
            ->assertSee('already halfway shared', false);
    }

    /**
     * APP_NAME is this repository's internal name ("Screenshut Telemetry" on the live deployment).
     * It must never reach a page the public sees.
     */
    public function test_it_shows_the_brand_rather_than_the_internal_app_name(): void
    {
        config(['app.name' => 'Internal Repo Name', 'app.brand' => 'Akukas']);

        $response = $this->get('/');

        $response->assertOk()->assertSee('Akukas')->assertDontSee('Internal Repo Name');
    }

    public function test_the_legal_pages_use_the_brand_too(): void
    {
        config(['app.name' => 'Internal Repo Name', 'app.brand' => 'Akukas']);

        $this->get('/privacy')->assertOk()->assertDontSee('Internal Repo Name');
    }

    public function test_arabic_is_negotiated_from_accept_language_and_renders_right_to_left(): void
    {
        $this->get('/', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false);

        $this->get('/', ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertOk()
            ->assertSee('dir="ltr"', false);
    }

    public function test_an_explicit_lang_query_wins_over_the_header(): void
    {
        $this->get('/?lang=ar', ['Accept-Language' => 'en'])->assertOk()->assertSee('lang="ar"', false);
        $this->get('/?lang=en', ['Accept-Language' => 'ar'])->assertOk()->assertSee('lang="en"', false);
    }

    /** An unknown or hostile ?lang= falls back rather than 404ing or reaching a missing key. */
    public function test_an_unsupported_language_falls_back_to_english(): void
    {
        $this->get('/?lang=fr')->assertOk()->assertSee('lang="en"', false);
        $this->get('/?lang[]=ar')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_the_store_button_is_an_unlinked_badge_until_the_listing_exists(): void
    {
        config(['app.play_url' => null]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Coming soon to Google Play')
            ->assertDontSee('play.google.com');
    }

    public function test_the_store_button_links_once_a_url_is_configured(): void
    {
        config(['app.play_url' => 'https://play.google.com/store/apps/details?id=ly.akukas.akukasapp']);

        $this->get('/')
            ->assertOk()
            ->assertSee('id=ly.akukas.akukasapp', false)
            ->assertDontSee('Coming soon to Google Play');
    }

    /**
     * The footer is where Play Console's required URLs are reachable from. A renamed legal route
     * would break these links silently, since Blade's route() would throw only on render.
     */
    public function test_the_footer_links_to_every_legal_document(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (['privacy', 'terms', 'csae', 'account-deletion'] as $document) {
            $response->assertSee(route('legal.show', ['document' => $document, 'locale' => 'en']), false);
            $this->get("/{$document}/en")->assertOk();
        }
    }
}
