<?php

namespace App\Http\Requests;

use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any authenticated user may report anything visible to them — enforced by route
     * middleware, not here.
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
            'reportable_type' => ['required', 'string', Rule::in(array_keys(Report::REPORTABLE_TYPES))],
            'reportable_id' => ['required', 'integer', Rule::exists($this->reportableTable(), 'id')],
            'reason' => ['required', 'string', Rule::in(['spam', 'harassment', 'nudity', 'other'])],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reject reporting yourself as a "user" target — nonsensical, not a policy concern.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var User $user */
            $user = $this->user();

            if (
                $this->input('reportable_type') === 'user'
                && (int) $this->input('reportable_id') === $user->id
            ) {
                $validator->errors()->add('reportable_id', 'You cannot report yourself.');
            }
        });
    }

    /** Resolves the DB table to validate `reportable_id` existence against, based on the (unvalidated) type. */
    private function reportableTable(): string
    {
        $class = Report::REPORTABLE_TYPES[$this->input('reportable_type')] ?? null;

        return $class ? (new $class)->getTable() : 'reports';
    }
}
