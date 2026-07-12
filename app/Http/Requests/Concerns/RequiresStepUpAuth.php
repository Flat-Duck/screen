<?php

namespace App\Http\Requests\Concerns;

use App\Services\StepUpService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Replaces {@see RequiresCurrentPassword} on genuinely destructive/identity-changing
 * actions — see {@see StepUpService} for why "skip the check when there's no password"
 * isn't good enough for these specifically. All three possible fields
 * ('current_password', 'two_factor_code', 'confirmation_code') are declared optional
 * here; {@see StepUpService::verify()} (run via the `after` validator hook below) is
 * what actually enforces which one is required for a given account.
 */
trait RequiresStepUpAuth
{
    /** @return array<string, array<int, string>> */
    protected function stepUpRules(): array
    {
        return [
            'current_password' => ['sometimes', 'string'],
            'two_factor_code' => ['sometimes', 'string'],
            'confirmation_code' => ['sometimes', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A single-use step-up code (the email_code path) would otherwise get
            // consumed even when some *other* field on this request is invalid — the
            // request as a whole still fails, but the code is gone, forcing the caller
            // to request a fresh one just to retry with a corrected field. Only spend
            // it once everything else has already passed.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(StepUpService::class)->verify(
                    $this->user(),
                    $this->only(['current_password', 'two_factor_code', 'confirmation_code']),
                );
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
