<?php

namespace App\Actions\Media;

use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Guarantees the account has its default screenshot folders, and returns every folder it owns.
 *
 * Seeding lazily rather than at registration means one code path covers accounts created before
 * the folders migration, accounts created after it, and anything the create-user flow grows into
 * later. It costs one indexed SELECT on the overwhelmingly common path where folders already
 * exist, so both the folder listing and the upload endpoint can call it unconditionally.
 */
class EnsureDefaultPrivateSaveFolders
{
    /** @return Collection<int, PrivateSaveFolder> */
    public function __invoke(User $user): Collection
    {
        $folders = $user->privateSaveFolders()->get();

        if ($folders->isNotEmpty()) {
            return $folders;
        }

        $now = now();
        $position = 0;
        $rows = [];
        foreach (PrivateSaveFolder::DEFAULTS as $slug => $name) {
            $rows[] = [
                'user_id' => $user->getKey(),
                'slug' => $slug,
                'name' => $name,
                'is_default' => true,
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // insertOrIgnore, not create(): a brand-new account's first upload and first folder
        // listing can land concurrently, and both would see an empty set. The unique
        // (user_id, slug) index turns that race into a failed request otherwise.
        PrivateSaveFolder::query()->insertOrIgnore($rows);

        return $user->privateSaveFolders()->get();
    }
}
