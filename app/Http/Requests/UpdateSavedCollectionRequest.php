<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavedCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'version' => ['required', 'integer', 'min:1'],
            'visibility' => ['sometimes', Rule::in(['private'])],
        ];
    }

    /**
     * Declares the shape the rules above actually produce, so the services these values are
     * handed to keep checking their `@param array{...}` contracts. Only ever called without a
     * key here — the shape describes that call.
     *
     * @return array{name?: string, description?: string|null, position?: int, version: int, visibility?: string}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
