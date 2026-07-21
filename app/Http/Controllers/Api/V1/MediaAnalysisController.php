<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\CreateMediaAnalysis;
use App\Actions\Media\DeleteMediaAnalysis;
use App\Actions\Media\PublishMediaAnalysis;
use App\Enums\UserRestrictionType;
use App\Http\Requests\PublishMediaAnalysisRequest;
use App\Http\Requests\StoreMediaAnalysisRequest;
use App\Http\Resources\MediaAnalysisResource;
use App\Http\Resources\PostResource;
use App\Models\MediaAnalysis;
use App\Models\User;
use App\Services\SavedPostService;
use App\Services\UserRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaAnalysisController extends Controller
{
    public function __construct(
        private readonly UserRestrictionService $restrictions,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function store(StoreMediaAnalysisRequest $request, CreateMediaAnalysis $create): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var list<array{alt_text?: string|null}> $metadata */
        $metadata = array_values($request->validated('media_metadata', []));
        $analysis = $create($user, array_values($request->file('images', [])), $metadata);

        return (new MediaAnalysisResource($analysis))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $token): MediaAnalysisResource
    {
        return new MediaAnalysisResource($this->resolve($request, $token)->load('items'));
    }

    public function publish(PublishMediaAnalysisRequest $request, string $token, PublishMediaAnalysis $publish): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->restrictions->enforce($user, UserRestrictionType::Posting);
        $post = $publish($user, $this->resolve($request, $token), $request->validated());
        $post->loadCount(['likes', 'comments']);
        $post->is_liked = false;
        $post->is_saved = $this->savedPosts->isSaved($user, $post);

        return (new PostResource($post))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $token, DeleteMediaAnalysis $delete): JsonResponse
    {
        $delete($this->resolve($request, $token, allowExpired: true));

        return response()->json(null, 204);
    }

    private function resolve(Request $request, string $token, bool $allowExpired = false): MediaAnalysis
    {
        $analysis = MediaAnalysis::query()
            ->where('token', $token)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();

        if (! $allowExpired && $analysis->isExpired()) {
            abort(410, 'The media analysis has expired.');
        }

        return $analysis;
    }
}
