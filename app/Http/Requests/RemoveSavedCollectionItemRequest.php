<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RemoveSavedCollectionItemRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
