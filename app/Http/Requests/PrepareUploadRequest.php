<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrepareUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'content_type' => ['required', 'string', Rule::in(config('social.uploads.allowed_mime_types'))],
            'byte_size' => ['required', 'integer', 'min:1', 'max:'.config('social.uploads.max_size_bytes')],
        ];
    }
}
