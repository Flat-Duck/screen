<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Confirms $this->user()'s current password for a sensitive action — without relying on
 * Laravel's `current_password` validation rule, which checks the default `web` guard and
 * never sees a Sanctum-authenticated user (see SetPasswordRequest, where this logic was
 * first written).
 *
 * Only required when the account actually has a password set: a social-only account has
 * nothing to confirm here and relies on possessing a valid bearer token instead, same as
 * every other authenticated endpoint — don't lock those accounts out of sensitive actions
 * just because they've never set a password.
 */
trait RequiresCurrentPassword
{
    /**
     * @return array<int, Rule|Closure|string>
     */
    protected function currentPasswordRule(): array
    {
        $hasExistingPassword = $this->user()?->password !== null;

        return [
            Rule::requiredIf($hasExistingPassword),
            'string',
            function (string $attribute, mixed $value, Closure $fail) use ($hasExistingPassword): void {
                if ($hasExistingPassword && ! Hash::check((string) $value, $this->user()->password)) {
                    $fail(__('The provided password does not match your current password.'));
                }
            },
        ];
    }
}
