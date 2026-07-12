<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresCurrentPassword;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangeEmailRequest extends FormRequest
{
    use RequiresCurrentPassword;

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
            'current_password' => $this->currentPasswordRule(),
            'email' => [
                'required', 'string', 'email', 'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === $this->user()->email) {
                        $fail(__('This is already your current email address.'));
                    }
                },
            ],
        ];
    }
}
