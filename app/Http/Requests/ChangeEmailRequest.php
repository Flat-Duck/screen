<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresStepUpAuth;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangeEmailRequest extends FormRequest
{
    use RequiresStepUpAuth;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->stepUpRules(),
            'email' => [
                'required', 'string', 'email', 'max:255',
                'unique:users,email',
                // Someone else's *pending* (unconfirmed) email counts as taken too —
                // otherwise two users could both request the same new address and only
                // the second confirmation would fail, as a raw 500 from the DB's unique
                // index (see the pending_email migration) rather than a clean 422 here.
                'unique:users,pending_email',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === $this->user()->email) {
                        $fail(__('This is already your current email address.'));
                    }
                },
            ],
        ];
    }
}
