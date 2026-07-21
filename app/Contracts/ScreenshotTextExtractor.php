<?php

namespace App\Contracts;

use App\Data\Screenshots\TextExtractionResult;

interface ScreenshotTextExtractor
{
    public function extract(string $path): TextExtractionResult;

    public function version(): string;
}
