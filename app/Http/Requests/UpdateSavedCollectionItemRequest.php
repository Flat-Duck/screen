<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedCollectionItemRequest extends FormRequest
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
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * Declares the shape the rules above actually produce, so the services these values are
     * handed to keep checking their `@param array{...}` contracts. Only ever called without a
     * key here — the shape describes that call.
     *
     * @return array{collection_version: int, version: int, note?: string|null, position?: int}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
