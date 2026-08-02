<?php

namespace App\Services\Screenshots;

use App\Models\ScreenshotCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * v1 heuristic, not ML — deliberately simple and auditable rather than a black box, same spirit
 * as {@see SensitiveInformationAnalyzer}'s pattern-based approach. A post's `#hashtags` are the
 * primary signal (a hashtag matching a category's keyword scores {@see HASHTAG_WEIGHT} points);
 * the screenshot's OCR'd text is the secondary "does the actual content back this up" signal
 * (an OCR word matching scores {@see OCR_WEIGHT}, much lower — OCR text is noisy and a single
 * incidental word shouldn't out-vote a deliberate hashtag). The highest-scoring category wins if
 * it clears {@see MIN_SCORE}; ties keep whichever category was evaluated first (categories are
 * always queried in a stable `sort_order`, so this is deterministic, not query-order-dependent
 * accidentally). Below the threshold — including "no hashtags and no OCR text at all" — the post
 * is left uncategorized (`null`) rather than forced into a guess or a catch-all "other" bucket.
 */
class CategoryMatcher
{
    private const HASHTAG_WEIGHT = 3;

    private const OCR_WEIGHT = 1;

    private const MIN_SCORE = 3;

    public function match(?string $caption, ?string $ocrText): ?ScreenshotCategory
    {
        $hashtags = $this->extractHashtags($caption);
        $ocrWords = $this->extractWords($ocrText);
        if ($hashtags->isEmpty() && $ocrWords->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach (ScreenshotCategory::query()->active()->orderBy('sort_order')->get() as $category) {
            /** @var Collection<int, string> $keywords */
            $keywords = collect($category->keywords ?? []);
            if ($keywords->isEmpty()) {
                continue;
            }

            $score = $keywords->intersect($hashtags)->count() * self::HASHTAG_WEIGHT
                + $keywords->intersect($ocrWords)->count() * self::OCR_WEIGHT;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $category;
            }
        }

        return $bestScore >= self::MIN_SCORE ? $best : null;
    }

    /** @return Collection<int, string> */
    private function extractHashtags(?string $caption): Collection
    {
        if (! $caption) {
            return collect();
        }

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);

        /** @var list<string> $tags */
        $tags = [];
        foreach ($matches[1] as $tag) {
            $tags[] = Str::lower($tag);
        }

        return collect($tags)->unique()->values();
    }

    /** @return Collection<int, string> */
    private function extractWords(?string $text): Collection
    {
        if (! $text) {
            return collect();
        }

        preg_match_all('/[\p{L}\p{N}]+/u', Str::lower($text), $matches);

        /** @var list<string> $words */
        $words = $matches[0];

        return collect($words)->unique()->values();
    }
}
