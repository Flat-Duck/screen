<?php

namespace App\Data\Posts;

final readonly class StagedPostBatch
{
    /** @param list<StagedPostMedia> $media */
    public function __construct(
        public int $cleanupTaskId,
        public string $directory,
        public array $media,
    ) {}
}
