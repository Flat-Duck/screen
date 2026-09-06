<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public front page.
 *
 * Locale is negotiated the same way {@see LegalController} does it, for the same reason — an
 * Arabic-speaking visitor following a bare link should land in Arabic — but the override arrives
 * as `?lang=` rather than a path segment. A `/ar` path would be swallowed by the catch-all
 * `{document}/{locale?}` legal route and 404, and moving that route to accommodate this page
 * would be the tail wagging the dog.
 */
class LandingController extends Controller
{
    /** @var list<string> */
    private const LOCALES = ['en', 'ar'];

    /** @var list<string> */
    private const RTL_LOCALES = ['ar'];

    public function __invoke(Request $request): Response
    {
        $locale = $this->resolveLocale($request);

        return response()->view('landing', [
            'locale' => $locale,
            'dir' => in_array($locale, self::RTL_LOCALES, true) ? 'rtl' : 'ltr',
            'otherLocale' => $locale === 'ar' ? 'en' : 'ar',
            'brand' => (string) config('app.brand'),
            'playUrl' => config('app.play_url'),
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $requested = $request->query('lang');

        if (is_string($requested) && in_array($requested, self::LOCALES, true)) {
            return $requested;
        }

        $preferred = (string) $request->getPreferredLanguage(self::LOCALES);

        return in_array($preferred, self::LOCALES, true) ? $preferred : 'en';
    }
}
