<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * No prior auth exists yet — `two_factor_token` is the proof of the completed
     * first login step, not a session/user context.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Exactly one of `code` (a TOTP code from an authenticator app) or `recovery_code`
     * is expected — `required_without` on each expresses that without a custom rule.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'two_factor_token' => ['required', 'string'],
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
