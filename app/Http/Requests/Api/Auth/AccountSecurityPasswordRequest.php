<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Foundation\Http\FormRequest;

class AccountSecurityPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', new PasswordWithinHashLimit],
        ];
    }
}
