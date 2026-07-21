<?php

namespace App\Data\Posts;

use Illuminate\Http\UploadedFile;

final readonly class CreatePostData
{
    /**
     * @param  list<UploadedFile>  $images
     * @param  list<array{alt_text?: string|null}>  $mediaMetadata
     */
    public function __construct(
        public ?string $caption,
        public array $images,
        public bool $commentsEnabled = true,
        public bool $repostsEnabled = true,
        public array $mediaMetadata = [],
        public ?int $categoryId = null,
        public ?string $sourceApplication = null,
        public ?string $sourceUrl = null,
        public ?string $contentWarning = null,
    ) {}
}
