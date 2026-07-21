<?php

namespace App\Data\Recommendations;

use App\Models\Post;
use Illuminate\Support\Collection;

final readonly class RecommendationFeedPage
{
    /** @param Collection<int, Post> $posts */
    public function __construct(
        public Collection $posts,
        public string $sessionId,
        public string $requestId,
        public ?string $nextCursor,
    ) {}
}
