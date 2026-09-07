<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors {@see ShowForYouFeedRequest} so both feeds accept the same paging controls.
 *
 * `/feed` previously hardcoded 15 while the Android client's PagingConfig was built around 20,
 * which meant Paging's own prefetch arithmetic was working off a page size it never actually
 * received.
 */
class ShowFeedRequest extends FormRequest
{
    /** The default when the client does not ask, unchanged from before this request existed. */
    public const DEFAULT_PER_PAGE = 15;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:2048'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
