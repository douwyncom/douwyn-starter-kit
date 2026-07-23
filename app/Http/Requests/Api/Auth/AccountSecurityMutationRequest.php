<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountSecurityMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', new PasswordWithinHashLimit],
            'otp' => [
                'nullable',
                Rule::prohibitedIf($this->filled('recovery_code')),
                'digits:6',
            ],
            'recovery_code' => [
                'nullable',
                Rule::prohibitedIf($this->filled('otp')),
                'string',
                'max:64',
            ],
        ];
    }
}
