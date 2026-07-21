<?php

namespace App\Contracts;

use App\Data\Screenshots\SafetyAnalysisResult;

interface ScreenshotSafetyAnalyzer
{
    public function analyze(?string $text): SafetyAnalysisResult;

    public function version(): string;
}
