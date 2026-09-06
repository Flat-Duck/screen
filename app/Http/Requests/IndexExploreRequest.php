<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexExploreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:100'],
            // Format only, same reasoning as UpdateProfileRequest's own country_code rule —
            // not validated against the full ISO 3166-1 alpha-2 list.
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
        ];
    }
}
