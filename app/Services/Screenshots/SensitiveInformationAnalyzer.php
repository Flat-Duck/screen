<?php

namespace App\Services\Screenshots;

use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Data\Screenshots\SafetyAnalysisResult;

class SensitiveInformationAnalyzer implements ScreenshotSafetyAnalyzer
{
    public function analyze(?string $text): SafetyAnalysisResult
    {
        if ($text === null || trim($text) === '') {
            return new SafetyAnalysisResult([]);
        }

        $findings = [];
        foreach ((array) config('social.processing.safety.patterns', []) as $category => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if (is_string($pattern) && preg_match($pattern, $text) === 1) {
                    $findings[] = [
                        'category' => (string) $category,
                        // The current plain-text OCR provider cannot safely map a match back
                        // to a word box. A normalized full-image region is honest and lets the
                        // client offer whole-image redaction without exposing the match.
                        'region' => ['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0],
                    ];
                    break;
                }
            }
        }

        return new SafetyAnalysisResult($findings);
    }

    public function version(): string
    {
        return 'sensitive-patterns-v1';
    }
}
