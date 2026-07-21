<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresStepUpAuth;
use Illuminate\Foundation\Http\FormRequest;

class PermanentlyDeletePostRequest extends FormRequest
{
    use RequiresStepUpAuth;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->stepUpRules();
    }
}
