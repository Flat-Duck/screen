<?php

namespace App\Services\Screenshots;

/**
 * How much two OCR readings of the same image actually agree.
 *
 * The publish-time verdict compares which CategoryMatcher category each text produced, which
 * is the right test for "is this device lying" but tells you nothing about quality: two
 * unrelated texts that both match "Social" score as agreement. This produces the number you
 * can actually plot — token-level Jaccard over normalised text.
 *
 * Jaccard on tokens rather than an edit distance on characters, because OCR error is
 * overwhelmingly word-level (a missed line, a mangled word) and Levenshtein over 50 000
 * characters is both expensive and dominated by whitespace and layout noise that neither
 * reading got "wrong".
 */
class OcrTextSimilarity
{
    /**
     * @return float 0.0 (nothing in common) to 1.0 (identical token sets). Two texts that are
     *               both empty count as full agreement — they agree there is no text.
     */
    public function score(?string $left, ?string $right): float
    {
        $leftTokens = $this->tokenize($left);
        $rightTokens = $this->tokenize($right);

        if ($leftTokens === [] && $rightTokens === []) {
            return 1.0;
        }

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        // Both sets are non-empty by the guards above, so the union is always at least 1.
        $intersection = count(array_intersect_key($leftTokens, $rightTokens));
        $union = count($leftTokens + $rightTokens);

        return round($intersection / $union, 4);
    }

    /**
     * Lowercased, punctuation-stripped, deduplicated tokens keyed for O(1) intersection.
     * Unicode-aware so Arabic text tokenizes on the same footing as Latin — `\p{L}` and
     * `\p{N}` rather than an ASCII-only class.
     *
     * @return array<string, true>
     */
    private function tokenize(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $normalized = mb_strtolower($text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($words as $word) {
            // Single characters are mostly OCR noise and inflate both sides of the ratio.
            if (mb_strlen($word) > 1) {
                $tokens[$word] = true;
            }
        }

        return $tokens;
    }
}
