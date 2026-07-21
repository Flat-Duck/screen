<?php

namespace App\Http\Requests;

use App\Rules\SafeSourceUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublishMediaAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:2200'],
            'comments_enabled' => ['sometimes', 'boolean'],
            'reposts_enabled' => ['sometimes', 'boolean'],
            'category_id' => ['nullable', 'integer', Rule::exists('screenshot_categories', 'id')->where('is_active', true)],
            'source_application' => ['nullable', 'string', 'max:100'],
            'source_url' => ['nullable', 'string', 'max:2048', new SafeSourceUrl],
            'content_warning' => ['nullable', 'string', Rule::in(['sensitive', 'spoiler'])],
            'acknowledge_sensitive' => ['sometimes', 'boolean'],
        ];
    }
}
