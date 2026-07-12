<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
     * Only `notifications` exists today — see SettingsService's doc comment for how this
     * grows to more top-level keys without a new endpoint per setting.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notifications' => ['sometimes', 'array'],
            'notifications.likes' => ['sometimes', 'boolean'],
            'notifications.comments' => ['sometimes', 'boolean'],
            'notifications.follows' => ['sometimes', 'boolean'],
        ];
    }
}
