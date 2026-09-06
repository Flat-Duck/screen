<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistrationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'points_per_invite' => ['required', 'integer', 'min:0', 'max:100000'],
            'maturity_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
