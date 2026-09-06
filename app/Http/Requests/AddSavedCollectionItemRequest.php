<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddSavedCollectionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'collection_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
