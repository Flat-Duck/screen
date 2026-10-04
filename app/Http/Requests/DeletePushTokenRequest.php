<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeletePushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'fcm_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'session_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
