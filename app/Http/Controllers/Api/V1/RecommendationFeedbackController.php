<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use App\Services\Recommendations\RecommendationFeedbackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RecommendationFeedbackController extends Controller
{
    public function __construct(private readonly RecommendationFeedbackService $feedback) {}

    public function notInterested(Request $request, Post $post): Response
    {
        $this->feedback->notInterested($this->user($request), $post);

        return response()->noContent();
    }

    public function restoreInterest(Request $request, Post $post): Response
    {
        $this->feedback->restoreInterest($this->user($request), $post);

        return response()->noContent();
    }

    public function hide(Request $request, Post $post): Response
    {
        $this->feedback->hide($this->user($request), $post);

        return response()->noContent();
    }

    public function showFewerFromUser(Request $request, User $user): Response
    {
        $this->feedback->showFewerFromAuthor($this->user($request), $user);

        return response()->noContent();
    }

    public function showFewerFromHashtag(Request $request, Hashtag $hashtag): Response
    {
        $this->feedback->showFewerFromHashtag($this->user($request), $hashtag);

        return response()->noContent();
    }

    public function reset(Request $request): Response
    {
        $data = $request->validate(['clear_interests' => ['sometimes', 'boolean']]);
        $this->feedback->resetProfile($this->user($request), (bool) ($data['clear_interests'] ?? false));

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
