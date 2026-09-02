<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\EnsureDefaultPrivateSaveFolders;
use App\Http\Resources\PrivateSaveFolderResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrivateSaveFolderController extends Controller
{
    /** Always returns at least the three defaults, seeding them on first call if need be. */
    public function index(Request $request, EnsureDefaultPrivateSaveFolders $ensure): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $ensure($user);

        $folders = $user->privateSaveFolders()->withCount('privateSaves')->get();

        return PrivateSaveFolderResource::collection($folders);
    }
}
