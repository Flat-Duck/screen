<?php

namespace App\Actions\Posts;

use App\Contracts\MediaFileStore;
use App\Data\Posts\CreatePostData;
use App\Data\Posts\StagedPostMedia;
use App\Services\ImageProcessingService;
use Illuminate\Support\Str;
use Throwable;

class StagePostMedia
{
    public function __construct(
        private readonly ImageProcessingService $images,
        private readonly MediaFileStore $files,
    ) {}

    /** @return list<StagedPostMedia> */
    public function __invoke(CreatePostData $data): array
    {
        $directory = 'posts/'.Str::uuid();
        $staged = [];

        try {
            foreach ($data->images as $position => $image) {
                $stored = $this->images->storeOriginal($image, $directory);
                $staged[] = new StagedPostMedia(
                    position: $position,
                    path: $stored['path'],
                    width: $stored['width'],
                    height: $stored['height'],
                    mimeType: $stored['mime'],
                    sizeBytes: $stored['size'],
                );
            }
        } catch (Throwable $exception) {
            $this->cleanup($staged);

            throw $exception;
        }

        return $staged;
    }

    /** @param list<StagedPostMedia> $staged */
    public function cleanup(array $staged): void
    {
        try {
            $this->files->deletePaths(array_map(
                static fn (StagedPostMedia $media): string => $media->path,
                $staged,
            ));
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
