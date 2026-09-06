<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowForYouFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:2048'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
