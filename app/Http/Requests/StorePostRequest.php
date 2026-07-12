<?php

namespace App\Http\Requests;

use App\Data\Posts\CreatePostData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function toData(): CreatePostData
    {
        $data = $this->validated();

        return new CreatePostData(
            caption: isset($data['caption']) ? (string) $data['caption'] : null,
            images: array_values($this->file('images', [])),
        );
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * Any authenticated user may create a post — enforced by route middleware, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:2200'],
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240', 'dimensions:min_width=200,min_height=200'],
        ];
    }
}
