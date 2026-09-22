<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReserveInviteRequest extends FormRequest
{
    /** Device-token authenticated (see routes/api_v1.php) — no user session exists yet at this
     * point in the flow, same reasoning as RegisterUserRequest/GoogleLoginRequest. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'invite_code' => ['required', 'string', 'max:32'],
        ];
    }
}
