<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrivateSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'folder_id' => [
                'required',
                'integer',
                Rule::exists('private_save_folders', 'id')->where('user_id', $this->user()?->getKey()),
            ],
        ];
    }
}
