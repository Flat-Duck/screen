<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * No prior auth exists yet at sign-in time — that's the point of this endpoint.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access_token' => ['required', 'string'],
            // Structural shape only — see CompleteSocialLogin/InviteCodeService for the actual
            // required-when-invite-only-active + resolves-to-a-real-user checks, which only apply
            // when this call turns out to create a brand-new account.
            'invite_code' => ['nullable', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
