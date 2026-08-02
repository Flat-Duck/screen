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
            // No category_id/content_warning here — both are now derived server-side (see
            // PublishMediaAnalysis: App\Services\Screenshots\CategoryMatcher for category,
            // MediaAnalysisItem::safety_status for content_warning). A client sending either is
            // silently ignored, not rejected — they're simply absent from validated().
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'source_application' => ['nullable', 'string', 'max:100'],
            'source_url' => ['nullable', 'string', 'max:2048', new SafeSourceUrl],
            'acknowledge_sensitive' => ['sometimes', 'boolean'],
            // Posts directly into this group (in addition to the timeline) instead of the old
            // "post to timeline, then separately re-share into a group" two-step flow — see
            // PublishMediaAnalysis. Still just a normal Post underneath; GroupController::share's
            // POST /v1/groups/{group}/posts/{post} is unaffected and still works for sharing an
            // already-published post into a *second* group afterward.
            'group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')],
        ];
    }
}
