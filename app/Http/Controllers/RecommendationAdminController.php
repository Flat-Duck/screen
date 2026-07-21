<?php

namespace App\Http\Controllers;

use App\Models\DailyPostMetric;
use App\Models\FeatureFlag;
use App\Models\Post;
use App\Models\RecommendationExclusion;
use App\Models\RecommendationFeedSession;
use App\Models\User;
use App\Services\Recommendations\RecommendationAdministrationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RecommendationAdminController extends Controller
{
    public function index(): View
    {
        $prefix = (string) config('social.recommendations.hot_pool_prefix');
        try {
            $hotIds = Redis::zrevrange($prefix.':global', 0, 24);
        } catch (Throwable) {
            $hotIds = [];
        }
        $hotPosts = Post::query()->whereIn('id', $hotIds)->with('user')->get()->sortBy(function (Post $post) use ($hotIds): int {
            $position = array_search((string) $post->id, $hotIds, true);

            return $position === false ? PHP_INT_MAX : $position;
        });
        $anomalies = DailyPostMetric::query()->where('impressions', '>', 0)
            ->selectRaw('post_id, SUM(impressions) impressions, SUM(reports) reports, SUM(hides + not_interested) negative_feedback')
            ->groupBy('post_id')->orderByDesc('reports')->limit(25)->get();

        return view('recommendations.index', [
            'servingEnabled' => FeatureFlag::query()->where('key', 'recommendations.serving')->first()?->isActive() ?? true,
            'hotPosts' => $hotPosts,
            'sessions' => RecommendationFeedSession::query()->latest()->limit(20)->get(),
            'exclusions' => RecommendationExclusion::query()->with('post.user')->latest()->limit(50)->get(),
            'anomalies' => $anomalies,
        ]);
    }

    public function exclude(Request $request, Post $post, RecommendationAdministrationService $admin): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $admin->exclude($post, $this->user($request), $data['reason'], isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null);

        return back()->with('status', 'Post excluded from recommendations.');
    }

    public function restore(Request $request, RecommendationExclusion $exclusion, RecommendationAdministrationService $admin): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $admin->restore($exclusion, $this->user($request), $data['reason']);

        return back()->with('status', 'Recommendation exclusion removed.');
    }

    public function serving(Request $request, RecommendationAdministrationService $admin): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $admin->setServing($this->user($request), (bool) $data['enabled'], $data['reason']);

        return back()->with('status', 'Recommendation serving updated.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
