<?php

namespace App\Actions\Media;

use App\Models\MediaAnalysis;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An analysis token is also its idempotency key, scoped to its owner and publish input. */
final class PublishMediaAnalysisOnce
{
    public function __construct(private readonly PublishMediaAnalysis $publish) {}

    /** @param array<string, mixed> $data */
    public function __invoke(User $user, string $token, array $data): Post
    {
        abort_unless(Str::isUuid($token), 404);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $token, $data, $hash): Post {
            // Unique insertion waits for a concurrent publication to commit or roll back.
            // Lock before resolving the analysis: successful publication consumes that row.
            DB::table('media_publication_receipts')->insertOrIgnore([
                'analysis_token' => $token,
                'user_id' => $user->id,
                'request_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receipt = DB::table('media_publication_receipts')->where('analysis_token', $token)->lockForUpdate()->first();
            abort_unless($receipt !== null && (int) $receipt->user_id === $user->id, 404);
            if (! hash_equals($receipt->request_hash, $hash)) {
                throw new ConflictHttpException('This analysis was published with different input.');
            }
            if ($receipt->post_id !== null) {
                return Post::query()
                    ->where('user_id', $user->id)
                    ->whereKey((int) $receipt->post_id)
                    ->with(['media', 'user', 'category'])
                    ->firstOrFail();
            }

            $analysis = MediaAnalysis::query()->where('token', $token)->where('user_id', $user->id)->firstOrFail();
            $post = ($this->publish)($user, $analysis, $data);
            DB::table('media_publication_receipts')->where('analysis_token', $token)->update([
                'post_id' => $post->id,
                'updated_at' => now(),
            ]);

            return $post;
        });
    }
}
