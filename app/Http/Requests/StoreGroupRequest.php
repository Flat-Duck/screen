<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['sometimes', 'string', 'in:public,private'],
            'is_discoverable' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=100,min_height=100'],
        ];
    }

    /**
     * Declares the shape the rules above actually produce, so the services these values are
     * handed to keep checking their `@param array{...}` contracts. Only ever called without a
     * key here — the shape describes that call.
     *
     * @return array{name: string, description?: string|null, visibility?: string, is_discoverable?: bool, photo?: UploadedFile|null}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
