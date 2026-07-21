<?php

namespace App\Jobs;

use App\Contracts\PerceptualHasher;
use App\Models\PostMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ComputePostMediaPerceptualHash implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $postMediaId)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(PerceptualHasher $hasher): void
    {
        $media = PostMedia::find($this->postMediaId);
        if (! $media || ($media->hash_status === PostMedia::PROCESSING_READY && $media->hash_version === $hasher->version())) {
            return;
        }

        $media->update(['hash_status' => PostMedia::PROCESSING_PROCESSING]);
        $media->update([
            'perceptual_hash' => $hasher->hash($media->original_path),
            'hash_status' => PostMedia::PROCESSING_READY,
            'hash_version' => $hasher->version(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        PostMedia::query()->whereKey($this->postMediaId)->update(['hash_status' => PostMedia::PROCESSING_FAILED]);
    }
}
