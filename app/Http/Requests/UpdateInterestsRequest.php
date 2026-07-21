<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'interest_ids' => ['required', 'array', 'min:3', 'max:10'],
            'interest_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('interests', 'id')->where('is_active', true),
            ],
        ];
    }
}
