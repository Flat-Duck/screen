<?php

namespace App\Http\Requests;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any authenticated user may comment — enforced by route middleware, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2200'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:comments,id'],
        ];
    }

    /**
     * A reply's parent must belong to the same post and must itself be top-level —
     * one level of nesting only, enforced here rather than left to the database.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $parentId = $this->input('parent_id');

            if ($parentId === null) {
                return;
            }

            $parent = Comment::query()->find((int) $parentId);

            if ($parent === null) {
                return;
            }

            /** @var Post $post */
            $post = $this->route('post');

            if ($parent->post_id !== $post->id) {
                $validator->errors()->add('parent_id', 'The parent comment must belong to the same post.');
            } elseif ($parent->parent_id !== null) {
                $validator->errors()->add('parent_id', 'Replies can only be made to top-level comments.');
            }
        });
    }
}
