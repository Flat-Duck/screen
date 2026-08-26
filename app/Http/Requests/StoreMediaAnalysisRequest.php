<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Two mutually-exclusive ways to supply an analysis's images now — the legacy raw-multipart
 * `images` (bytes flow through this app), or `upload_ids` (previously-committed Upload rows
 * already sitting in R2, bytes never touch this app — see docs/SECURITY.md §12). Never both at
 * once: withValidator() below enforces that, since Laravel's own rule set can express "at least
 * one of these" but not "not both" as cleanly.
 */
class StoreMediaAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'images' => ['required_without:upload_ids', 'array', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240', 'dimensions:min_width=200,min_height=200'],
            'upload_ids' => ['required_without:images', 'array', 'max:10'],
            'upload_ids.*' => ['required', 'string', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('images') && $this->filled('upload_ids')) {
                $validator->errors()->add('upload_ids', 'Send either images or upload_ids, not both.');
            }
        });
    }
}
