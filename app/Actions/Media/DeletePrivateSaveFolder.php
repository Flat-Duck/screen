<?php

namespace App\Actions\Media;

use App\Models\PrivateSave;
use App\Models\PrivateSaveFolder;
use App\Models\User;

class DeletePrivateSaveFolder
{
    public function __construct(private readonly EnsureDefaultPrivateSaveFolders $ensureDefaults) {}

    /**
     * Deletes [$folder] and re-files anything inside it under General.
     *
     * The screenshots are the point, not the folder — deleting a folder must never take images
     * with it, and the `folder_id` foreign key's `nullOnDelete` would otherwise leave them
     * unfiled, invisible in every folder filter. The three seeded folders are refused outright
     * (see the controller), which is what guarantees General is always there to receive these.
     */
    public function __invoke(User $user, PrivateSaveFolder $folder): void
    {
        $general = ($this->ensureDefaults)($user)
            ->firstWhere('slug', PrivateSaveFolder::SLUG_GENERAL);

        PrivateSave::query()
            ->where('user_id', $user->getKey())
            ->where('folder_id', $folder->getKey())
            ->update(['folder_id' => $general?->getKey()]);

        $folder->delete();
    }
}
