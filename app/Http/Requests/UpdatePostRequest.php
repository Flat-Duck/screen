<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
