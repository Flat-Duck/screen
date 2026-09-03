<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrivateSaveFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rename only. The slug is deliberately *not* renamed with it: it is documented as the stable
     * per-user key clients match the seeded folders on, so renaming "General" to "Everything else"
     * must not break a client looking for `general`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:1', 'max:60']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }
}
