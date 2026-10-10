<?php

namespace App\Http\Requests;

use App\Services\PointRewardService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistrationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'points_per_invite' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'maturity_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'rewards' => ['sometimes', 'array'],
        ];

        foreach (array_keys(PointRewardService::catalog()) as $action) {
            $rules["rewards.{$action}.enabled"] = ['sometimes', 'boolean'];
            $rules["rewards.{$action}.points"] = ['required_with:rewards.'.$action, 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }
}
