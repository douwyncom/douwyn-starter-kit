<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RequestEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
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

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (! is_string($email)) {
            return;
        }

        $this->merge([
            'email' => Str::lower(trim($email)),
        ]);
    }
}
