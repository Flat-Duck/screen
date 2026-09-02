<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\CreatePrivateSave;
use App\Actions\Media\DeletePrivateSave;
use App\Actions\Media\EnsureDefaultPrivateSaveFolders;
use App\Http\Requests\IndexPrivateSaveRequest;
use App\Http\Requests\StorePrivateSaveRequest;
use App\Http\Requests\UpdatePrivateSaveRequest;
use App\Http\Resources\PrivateSaveResource;
use App\Models\PrivateSave;
use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrivateSaveController extends Controller
{
    public function store(
        StorePrivateSaveRequest $request,
        CreatePrivateSave $create,
        EnsureDefaultPrivateSaveFolders $ensure,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        // Runs even when the client named a folder: an account created after the backfill
        // migration gets its three defaults here, on its very first save.
        $folders = $ensure($user);
        $folderId = $request->integer('folder_id');
        $folder = $folderId > 0
            ? $folders->firstWhere('id', $folderId)
            : $folders->firstWhere('slug', PrivateSaveFolder::SLUG_GENERAL);

        $save = $create($user, $request->file('image'), $folder);

        return (new PrivateSaveResource($save->load('folder')))->response()->setStatusCode(201);
    }

    public function index(IndexPrivateSaveRequest $request): AnonymousResourceCollection
    {
        $folderId = $request->integer('folder_id');

        $saves = PrivateSave::query()
            ->where('user_id', $request->user()->id)
            ->when($folderId > 0, fn (Builder $query): Builder => $query->where('folder_id', $folderId))
            ->with('folder')
            ->latest('id')
            ->cursorPaginate(24);

        return PrivateSaveResource::collection($saves);
    }

    /** Moves a save between folders — the only correction path for a wrong pick at upload time. */
    public function update(UpdatePrivateSaveRequest $request, PrivateSave $privateSave): PrivateSaveResource
    {
        abort_unless($privateSave->user_id === $request->user()->id, 404);

        $privateSave->update(['folder_id' => $request->integer('folder_id')]);

        return new PrivateSaveResource($privateSave->load('folder'));
    }

    public function destroy(Request $request, PrivateSave $privateSave, DeletePrivateSave $delete): JsonResponse
    {
        abort_unless($privateSave->user_id === $request->user()->id, 404);

        $delete($privateSave);

        return response()->json(null, 204);
    }
}
