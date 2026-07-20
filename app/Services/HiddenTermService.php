<?php

namespace App\Services;

use App\Enums\HiddenTermType;
use App\Models\User;
use App\Models\UserHiddenTerm;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;

class HiddenTermService
{
    public function __construct(private readonly HiddenTermNormalizer $normalizer) {}

    /** @return CursorPaginator<int, UserHiddenTerm> */
    public function termsFor(User $user, int $perPage = 50): CursorPaginator
    {
        return $user->hiddenTerms()->latest('id')->cursorPaginate($perPage);
    }

    public function add(User $user, string $value, HiddenTermType $type): UserHiddenTerm
    {
        if ($user->hiddenTerms()->count() >= 100) {
            throw ValidationException::withMessages(['value' => 'You can save at most 100 hidden terms.']);
        }

        $normalized = $this->normalizer->normalize($value);
        if ($normalized === '') {
            throw ValidationException::withMessages(['value' => 'The hidden term must contain letters or numbers.']);
        }

        return $user->hiddenTerms()->firstOrCreate(
            ['normalized_hash' => hash('sha256', $normalized)],
            ['original_value' => $value, 'normalized_value' => $normalized, 'type' => $type],
        );
    }

    public function remove(User $user, UserHiddenTerm $term): void
    {
        abort_unless($term->user_id === $user->id, 404);
        $term->delete();
    }
}
