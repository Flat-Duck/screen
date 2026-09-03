<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\CreatePrivateSaveFolder;
use App\Actions\Media\DeletePrivateSaveFolder;
use App\Actions\Media\EnsureDefaultPrivateSaveFolders;
use App\Http\Requests\StorePrivateSaveFolderRequest;
use App\Http\Requests\UpdatePrivateSaveFolderRequest;
use App\Http\Resources\PrivateSaveFolderResource;
use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrivateSaveFolderController extends Controller
{
    /** Always returns at least the three defaults, seeding them on first call if need be. */
    public function index(Request $request, EnsureDefaultPrivateSaveFolders $ensure): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $ensure($user);

        $folders = $user->privateSaveFolders()->withCount('privateSaves')->get();

        return PrivateSaveFolderResource::collection($folders);
    }

    public function store(StorePrivateSaveFolderRequest $request, CreatePrivateSaveFolder $create): JsonResponse
    {
        $user = $this->user($request);
        // The new folder is appended after the defaults, so seed them first on an account that
        // has never opened the picker — otherwise its first custom folder would land at position 1
        // and then be shuffled by the seeding that follows.
        app(EnsureDefaultPrivateSaveFolders::class)($user);

        $folder = $create($user, $request->string('name')->toString());

        return (new PrivateSaveFolderResource($folder->loadCount('privateSaves')))
            ->response()
            ->setStatusCode(201);
    }

    /** Rename. Allowed on the seeded folders too — their `name` is user-facing text; it is only
     * their existence, keyed by `slug`, that is fixed. */
    public function update(
        UpdatePrivateSaveFolderRequest $request,
        PrivateSaveFolder $privateSaveFolder,
    ): PrivateSaveFolderResource {
        $this->authorizeOwnership($request, $privateSaveFolder);

        $privateSaveFolder->update(['name' => $request->string('name')->toString()]);

        return new PrivateSaveFolderResource($privateSaveFolder->loadCount('privateSaves'));
    }

    /**
     * Deletes a custom folder, re-filing its screenshots under General.
     *
     * The three seeded folders are refused with 422 rather than 403: this is not a permissions
     * problem the user could resolve, it is a rule about which folders exist at all, and a client
     * should surface it as "you can't delete this one", not "you're not allowed".
     */
    public function destroy(
        Request $request,
        PrivateSaveFolder $privateSaveFolder,
        DeletePrivateSaveFolder $delete,
    ): JsonResponse {
        $user = $this->user($request);
        $this->authorizeOwnership($request, $privateSaveFolder);

        if ($privateSaveFolder->is_default) {
            return response()->json([
                'message' => __('The default folders cannot be deleted.'),
                'errors' => ['folder' => [__('The default folders cannot be deleted.')]],
            ], 422);
        }

        $delete($user, $privateSaveFolder);

        return response()->json(null, 204);
    }

    /** 404, not 403 — someone else's folder should not be distinguishable from one that never
     * existed, same convention as PrivateSaveController's own ownership checks. */
    private function authorizeOwnership(Request $request, PrivateSaveFolder $folder): void
    {
        abort_unless($folder->user_id === $request->user()?->getAuthIdentifier(), 404);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
