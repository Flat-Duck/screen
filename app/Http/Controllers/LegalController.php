<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves the public Terms of Service and Privacy Policy.
 *
 * These live on the web rather than inside the app for two reasons: Play Console requires a
 * publicly reachable Privacy Policy URL, and legal copy changes far more often than app
 * releases — baking it into `strings.xml` would make every wording tweak a Play review cycle.
 *
 * Content is Markdown on disk (`resources/legal/{document}.{locale}.md`) so the text can be
 * edited without touching Blade. It is author-controlled, never user input, so rendering it as
 * HTML is safe.
 */
class LegalController extends Controller
{
    /** Documents this controller will serve. Anything else 404s rather than probing the disk. */
    private const DOCUMENTS = ['privacy', 'terms'];

    /** Locales with a translation. `en` is the fallback when a translation is missing. */
    private const LOCALES = ['en', 'ar'];

    private const RTL_LOCALES = ['ar'];

    public function __invoke(string $document, ?string $locale = null): Response
    {
        abort_unless(in_array($document, self::DOCUMENTS, true), HttpResponse::HTTP_NOT_FOUND);

        $locale = $this->resolveLocale($locale);
        $path = $this->pathFor($document, $locale);

        // A locale can be advertised without its file existing yet; fall back rather than 404,
        // so a half-translated site still shows something legally meaningful.
        if (! is_file($path)) {
            $locale = 'en';
            $path = $this->pathFor($document, $locale);
        }

        abort_unless(is_file($path), HttpResponse::HTTP_NOT_FOUND);

        $markdown = (string) file_get_contents($path);

        return response()->view('legal.show', [
            'document' => $document,
            'locale' => $locale,
            'dir' => in_array($locale, self::RTL_LOCALES, true) ? 'rtl' : 'ltr',
            'html' => Str::markdown($markdown),
            'updatedAt' => filemtime($path) ?: null,
            'otherLocales' => array_values(array_diff(self::LOCALES, [$locale])),
        ]);
    }

    /**
     * Explicit path segment wins; otherwise negotiate from Accept-Language so an Arabic-speaking
     * visitor following a bare /privacy link gets Arabic.
     */
    private function resolveLocale(?string $requested): string
    {
        if ($requested !== null) {
            abort_unless(in_array($requested, self::LOCALES, true), HttpResponse::HTTP_NOT_FOUND);

            return $requested;
        }

        $preferred = request()->getPreferredLanguage(self::LOCALES);

        return in_array((string) $preferred, self::LOCALES, true) ? (string) $preferred : 'en';
    }

    private function pathFor(string $document, string $locale): string
    {
        return resource_path("legal/{$document}.{$locale}.md");
    }
}
