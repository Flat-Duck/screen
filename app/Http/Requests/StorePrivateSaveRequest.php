<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrivateSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'capture_id' => ['nullable', 'uuid'],
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
            // Optional — omitting it files the save under General. Scoped to the caller's own
            // folders so a guessed id can never drop a screenshot into someone else's account.
            'folder_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('private_save_folders', 'id')->where('user_id', $this->user()?->getKey()),
            ],
        ];
    }
}
