<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\CreatePrivateSave;
use App\Actions\Media\DeletePrivateSave;
use App\Http\Requests\StorePrivateSaveRequest;
use App\Http\Resources\PrivateSaveResource;
use App\Models\PrivateSave;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrivateSaveController extends Controller
{
    public function store(StorePrivateSaveRequest $request, CreatePrivateSave $create): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $save = $create($user, $request->file('image'));

        return (new PrivateSaveResource($save))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $saves = PrivateSave::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->cursorPaginate(24);

        return PrivateSaveResource::collection($saves);
    }

    public function destroy(Request $request, PrivateSave $privateSave, DeletePrivateSave $delete): JsonResponse
    {
        abort_unless($privateSave->user_id === $request->user()->id, 404);

        $delete($privateSave);

        return response()->json(null, 204);
    }
}
