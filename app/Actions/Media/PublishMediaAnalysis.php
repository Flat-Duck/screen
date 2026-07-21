<?php

namespace App\Actions\Media;

use App\Actions\Posts\SyncPostHashtags;
use App\Actions\Posts\SyncPostMentions;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\MediaAnalysis;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class PublishMediaAnalysis
{
    public function __construct(
        private readonly SyncPostHashtags $syncHashtags,
        private readonly SyncPostMentions $syncMentions,
    ) {}

    /** @param array<string, mixed> $data */
    public function __invoke(User $user, MediaAnalysis $analysis, array $data): Post
    {
        $post = DB::transaction(function () use ($user, $analysis, $data): Post {
            $locked = MediaAnalysis::query()->lockForUpdate()->with('items')->find($analysis->id);
            if (! $locked || $locked->user_id !== $user->id) {
                abort(404);
            }
            if ($locked->isExpired()) {
                throw new GoneHttpException('The media analysis has expired.');
            }
            if ($locked->status !== MediaAnalysis::STATUS_READY) {
                throw new ConflictHttpException('The media analysis is not ready to publish.');
            }

            $hasWarnings = $locked->items->contains(
                fn ($item): bool => $item->safety_status === PostMedia::SAFETY_WARNING,
            );
            if ($hasWarnings && ($data['acknowledge_sensitive'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'acknowledge_sensitive' => ['You must acknowledge sensitive-information warnings before publishing.'],
                ]);
            }

            $versions = $locked->items->pluck('analysis_version')->filter()->unique()->sort()->implode(',');
            $post = Post::create([
                'user_id' => $user->id,
                'caption' => $data['caption'] ?? null,
                'status' => Post::STATUS_PROCESSING,
                'comments_enabled' => $data['comments_enabled'] ?? true,
                'reposts_enabled' => $data['reposts_enabled'] ?? true,
                'category_id' => $data['category_id'] ?? null,
                'source_application' => $data['source_application'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'content_warning' => $data['content_warning'] ?? null,
                'safety_acknowledged_at' => $hasWarnings ? now() : null,
                'safety_analysis_version' => $versions === '' ? null : $versions,
            ]);
            ($this->syncHashtags)($post, $post->caption);

            foreach ($locked->items as $item) {
                $media = $post->media()->create([
                    'position' => $item->position,
                    'original_path' => $item->original_path,
                    'width' => $item->width,
                    'height' => $item->height,
                    'mime_type' => $item->mime_type,
                    'size_bytes' => $item->size_bytes,
                    'status' => PostMedia::STATUS_PENDING,
                    'alt_text' => $item->alt_text,
                    'ocr_text' => $item->ocr_text,
                    'ocr_language' => $item->ocr_language,
                    'ocr_status' => $item->ocr_status,
                    'ocr_version' => $item->analysis_version,
                    'safety_status' => $item->safety_status,
                    'safety_version' => $item->analysis_version,
                ]);
                GeneratePostMediaThumbnail::dispatch($media->id)->afterCommit();
            }

            if ($locked->cleanup_task_id !== null) {
                DB::table('media_cleanup_tasks')->where('id', $locked->cleanup_task_id)->delete();
            }
            $locked->delete();

            return $post->load(['media', 'user', 'category']);
        });

        ($this->syncMentions)($post, $post->caption);
        foreach ($post->media as $media) {
            ComputePostMediaPerceptualHash::dispatch($media->id);
        }

        return $post;
    }
}
