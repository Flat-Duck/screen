<?php

namespace App\Http\Requests;

use App\Data\Auth\RegisterUserData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    public function toData(): RegisterUserData
    {
        $data = $this->validated();

        return new RegisterUserData(
            (string) $data['name'],
            (string) $data['username'],
            (string) $data['email'],
            (string) $data['password'],
            isset($data['invite_code']) ? (string) $data['invite_code'] : null,
        );
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * No prior auth exists yet at registration time — that's the point of this endpoint.
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
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::default()],
            // Structural shape only — whether one is actually required (invite-only mode) and
            // whether it resolves to a real user is checked inside RegisterUser/InviteCodeService,
            // not here, since that's a business rule keyed on live flag state, not request shape.
            'invite_code' => ['nullable', 'string', 'max:32'],
            // When present, takes priority over invite_code — AuthController resolves it via
            // InviteCodeService::resolveTicket() before building RegisterUserData, since a ticket
            // (from POST /v1/auth/invites/reserve) is proof the invite-gate screen already
            // validated a code, and toData() below should never need to know the difference.
            'invite_ticket' => ['nullable', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
