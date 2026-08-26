<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommitUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'image_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            // Device-claimed OCR text (see docs/SECURITY.md §4) — stored as-is for now. Nothing
            // reads or trusts this yet: no CategoryMatcher/spot-check wiring exists on this path,
            // that's Phase 2, separate from this upload-foundation work.
            'ocr_text' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
