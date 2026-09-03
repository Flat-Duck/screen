<?php

namespace App\Actions\Media;

use App\Models\PrivateSaveFolder;
use App\Models\User;
use Illuminate\Support\Str;

class CreatePrivateSaveFolder
{
    /** Beyond this an account is organising nothing and just making the picker unusable. */
    public const MAX_FOLDERS_PER_USER = 50;

    public function __invoke(User $user, string $name): PrivateSaveFolder
    {
        return PrivateSaveFolder::create([
            'user_id' => $user->getKey(),
            'slug' => $this->uniqueSlug($user, $name),
            'name' => $name,
            'is_default' => false,
            // Appended, not inserted: the three defaults keep positions 0-2 and stay where the
            // user learned to find them.
            'position' => (int) $user->privateSaveFolders()->max('position') + 1,
        ]);
    }

    /**
     * Slugs are unique per user, so "Trip 2024" twice would collide. Suffixes rather than rejects:
     * the name is what the user sees, the slug is an internal key, and refusing a duplicate *name*
     * would be a strange rule to explain — two folders may legitimately be called "Work".
     *
     * A name with no sluggable characters at all (emoji-only, or non-Latin script) slugs to an
     * empty string, which would produce "-2", "-3"… — fall back to a stable prefix instead.
     */
    private function uniqueSlug(User $user, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'folder';
        }
        $base = Str::limit($base, 40, '');

        $taken = $user->privateSaveFolders()
            ->where('slug', 'like', $base.'%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $suffix = 2;
        while (in_array($base.'-'.$suffix, $taken, true)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }
}
