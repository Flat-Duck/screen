<?php

namespace App\Http\Requests;

use App\Enums\HiddenTermType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHiddenTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:100'],
            'type' => ['sometimes', Rule::enum(HiddenTermType::class)],
        ];
    }
}
