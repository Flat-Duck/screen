<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateInterestsRequest;
use App\Models\User;
use App\Services\InterestPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InterestController extends Controller
{
    public function __construct(private readonly InterestPreferenceService $preferences) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $selected = $user->interests()->pluck('interests.id');

        return response()->json([
            'data' => $this->preferences->catalog()->map(fn ($interest): array => [
                'id' => $interest->id,
                'name' => $interest->name,
                'slug' => $interest->slug,
                'icon' => $interest->icon,
                'description' => $interest->description,
                'is_selected' => $selected->contains($interest->id),
                'categories' => $interest->categories->map->only(['id', 'slug', 'name'])->values(),
                'hashtags' => $interest->hashtags->map->only(['id', 'name'])->values(),
            ])->values(),
            'onboarding' => $this->preferences->status($user),
        ]);
    }

    public function selected(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $interests = $user->interests()->active()->orderBy('sort_order')->get(['interests.id', 'name', 'slug', 'icon']);

        return response()->json([
            'data' => $interests->map(fn ($interest): array => [
                'id' => $interest->id,
                'name' => $interest->name,
                'slug' => $interest->slug,
                'icon' => $interest->icon,
            ])->values(),
            'onboarding' => $this->preferences->status($user),
        ]);
    }

    public function update(UpdateInterestsRequest $request): JsonResponse
    {
        $user = $this->user($request);
        /** @var list<int> $ids */
        $ids = $request->validated('interest_ids');
        $this->preferences->replace($user, $ids);

        return $this->selected($request);
    }

    public function skip(Request $request): Response
    {
        $this->preferences->skip($this->user($request));

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
