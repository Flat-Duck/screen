<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Play Console requires a reachable Privacy Policy URL, and the Android app links out to these
 * pages. A regression here is not cosmetic: a 404 on /privacy can hold up a store review.
 */
class LegalPagesTest extends TestCase
{
    public static function documentProvider(): array
    {
        return [['privacy'], ['terms']];
    }

    #[DataProvider('documentProvider')]
    public function test_documents_are_publicly_reachable_without_authentication(string $document): void
    {
        $this->get("/{$document}")->assertOk();
    }

    #[DataProvider('documentProvider')]
    public function test_each_locale_renders_with_the_right_direction(string $document): void
    {
        $this->get("/{$document}/en")->assertOk()->assertSee('dir="ltr"', false);
        $this->get("/{$document}/ar")->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_language_is_negotiated_from_the_accept_language_header(): void
    {
        $this->get('/privacy', ['Accept-Language' => 'ar'])->assertOk()->assertSee('lang="ar"', false);
        $this->get('/privacy', ['Accept-Language' => 'en-GB,en;q=0.9'])->assertOk()->assertSee('lang="en"', false);
    }

    public function test_unknown_documents_and_locales_are_not_found(): void
    {
        $this->get('/cookies')->assertNotFound();
        $this->get('/privacy/fr')->assertNotFound();
    }

    /** Guards against the routes shadowing real application paths. */
    public function test_the_catch_all_shape_does_not_swallow_other_routes(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/')->assertOk();
    }

    #[DataProvider('documentProvider')]
    public function test_markdown_is_rendered_as_html_not_shown_raw(string $document): void
    {
        $response = $this->get("/{$document}/en");
        $response->assertOk();
        $response->assertSee('<h2', false);
        $response->assertDontSee('## ', false);
    }
}
