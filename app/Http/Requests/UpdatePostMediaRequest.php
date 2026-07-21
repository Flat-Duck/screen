<?php

namespace App\Http\Requests;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof Post && $this->user()?->can('update', $post) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['alt_text' => ['required', 'nullable', 'string', 'max:1000']];
    }
}
