<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HiddenTermType;
use App\Http\Requests\StoreHiddenTermRequest;
use App\Http\Resources\HiddenTermResource;
use App\Models\User;
use App\Models\UserHiddenTerm;
use App\Services\HiddenTermService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HiddenTermController extends Controller
{
    public function __construct(private readonly HiddenTermService $terms) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return HiddenTermResource::collection($this->terms->termsFor($user));
    }

    public function store(StoreHiddenTermRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $term = $this->terms->add($user, $data['value'], HiddenTermType::from($data['type'] ?? HiddenTermType::Word->value));

        return (new HiddenTermResource($term))->response()->setStatusCode($term->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, UserHiddenTerm $hiddenTerm): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->terms->remove($user, $hiddenTerm);

        return response()->json(null, 204);
    }
}
