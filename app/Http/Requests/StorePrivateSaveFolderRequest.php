<?php

namespace App\Http\Requests;

use App\Actions\Media\CreatePrivateSaveFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePrivateSaveFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // 60 matches the `name` column; the slug is derived server-side, never sent.
        return ['name' => ['required', 'string', 'min:1', 'max:60']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = $this->user()?->privateSaveFolders()->count() ?? 0;
            if ($count >= CreatePrivateSaveFolder::MAX_FOLDERS_PER_USER) {
                $validator->errors()->add('name', __('You have reached the maximum number of folders.'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }
}
