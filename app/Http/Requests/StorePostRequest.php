<?php

namespace App\Http\Requests;

use App\Data\Posts\CreatePostData;
use App\Rules\SafeSourceUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
    public function toData(): CreatePostData
    {
        $data = $this->validated();

        return new CreatePostData(
            caption: isset($data['caption']) ? (string) $data['caption'] : null,
            images: array_values($this->file('images', [])),
            commentsEnabled: (bool) ($data['comments_enabled'] ?? true),
            repostsEnabled: (bool) ($data['reposts_enabled'] ?? true),
            mediaMetadata: array_values($data['media_metadata'] ?? []),
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            sourceApplication: isset($data['source_application']) ? (string) $data['source_application'] : null,
            sourceUrl: isset($data['source_url']) ? (string) $data['source_url'] : null,
            contentWarning: isset($data['content_warning']) ? (string) $data['content_warning'] : null,
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
            'comments_enabled' => ['sometimes', 'boolean'],
            'reposts_enabled' => ['sometimes', 'boolean'],
            'media_metadata' => ['sometimes', 'array'],
            'media_metadata.*' => ['array:alt_text'],
            'media_metadata.*.alt_text' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer', Rule::exists('screenshot_categories', 'id')->where('is_active', true)],
            'source_application' => ['nullable', 'string', 'max:100'],
            'source_url' => ['nullable', 'string', 'max:2048', new SafeSourceUrl],
            'content_warning' => ['nullable', 'string', Rule::in(['sensitive', 'spoiler'])],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
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
