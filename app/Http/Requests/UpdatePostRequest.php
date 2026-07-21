<?php

namespace App\Http\Requests;

use App\Rules\SafeSourceUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by PostPolicy::update via the controller's authorize() call,
     * not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * v1 is caption-only — editing media (reorder/add/remove images) isn't supported.
     * `sometimes` (not just `nullable`) matters here: omitting `caption` entirely leaves it
     * unchanged, while sending it explicitly as null clears it — see UpdatePost.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caption' => ['sometimes', 'nullable', 'string', 'max:2200'],
            'comments_enabled' => ['sometimes', 'boolean'],
            'reposts_enabled' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('screenshot_categories', 'id')->where('is_active', true)],
            'source_application' => ['sometimes', 'nullable', 'string', 'max:100'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:2048', new SafeSourceUrl],
            'content_warning' => ['sometimes', 'nullable', 'string', Rule::in(['sensitive', 'spoiler'])],
        ];
    }
}
