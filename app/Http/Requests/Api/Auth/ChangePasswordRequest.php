<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', new PasswordWithinHashLimit],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', new PasswordWithinHashLimit, Password::min(8)->letters()->numbers()->max(72)],
        ];
    }
}
