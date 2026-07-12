<?php

namespace App\Data\Posts;

final readonly class StagedPostMedia
{
    public function __construct(
        public int $position,
        public string $path,
        public int $width,
        public int $height,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
