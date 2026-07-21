<?php

namespace App\Data\Screenshots;

final readonly class TextExtractionResult
{
    public function __construct(
        public string $text,
        public ?string $language,
    ) {}
}
