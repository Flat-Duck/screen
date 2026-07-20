<?php

namespace App\Data\Posts;

use Illuminate\Http\UploadedFile;

final readonly class CreatePostData
{
    /** @param list<UploadedFile> $images */
    public function __construct(
        public ?string $caption,
        public array $images,
        public bool $commentsEnabled = true,
        public bool $repostsEnabled = true,
    ) {}
}
