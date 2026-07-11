<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Always operates on $request->user() — there's no {user} route param to check ownership of.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `current_password` is only required once the account already has a password —
     * a social-only account setting its first password doesn't have one to confirm.
     * Checked manually against $this->user() (not the `current_password` rule, which
     * validates against the default `web` guard and never sees a Sanctum-authenticated
     * user).
     *
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        $hasExistingPassword = $this->user()?->password !== null;

        return [
            'current_password' => [
                Rule::requiredIf($hasExistingPassword),
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($hasExistingPassword): void {
                    if ($hasExistingPassword && ! Hash::check((string) $value, $this->user()->password)) {
                        $fail(__('The provided password does not match your current password.'));
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::default()],
        ];
    }
}
