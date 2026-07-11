<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Always operates on $request->user() — there's no {user} route param to check ownership of.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `username` is the one field social sign-in never collects up front — this is
     * also the "complete your profile" endpoint for setting it after the fact.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'sometimes', 'string', 'min:3', 'max:30', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($this->user()->id),
            ],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=100,min_height=100'],
        ];
    }
}
