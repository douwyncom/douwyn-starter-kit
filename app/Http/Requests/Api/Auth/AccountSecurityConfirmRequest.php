<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AccountSecurityConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'setup_token' => ['required', 'string', 'min:40', 'max:255'],
            'otp' => ['required', 'digits:6'],
        ];
    }
}
