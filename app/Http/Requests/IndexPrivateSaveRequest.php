<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPrivateSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Omit to list every folder. Validated rather than silently scoped, so a client bug
            // surfaces as a 422 instead of an empty-looking folder.
            'folder_id' => [
                'sometimes',
                'integer',
                Rule::exists('private_save_folders', 'id')->where('user_id', $this->user()?->getKey()),
            ],
        ];
    }
}
