<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240', 'dimensions:min_width=200,min_height=200'],
            'media_metadata' => ['sometimes', 'array'],
            'media_metadata.*' => ['array:alt_text'],
            'media_metadata.*.alt_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $metadata = $this->input('media_metadata');
            $images = $this->file('images', []);
            if (is_array($metadata) && (! array_is_list($metadata) || count($metadata) !== count($images))) {
                $validator->errors()->add('media_metadata', 'Media metadata must be an ordered list with one item for every image.');
            }
        }];
    }
}
