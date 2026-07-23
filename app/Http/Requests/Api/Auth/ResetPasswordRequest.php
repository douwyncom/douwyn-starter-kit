<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends AccountActionTokenRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'password' => [
                'required',
                'string',
                'confirmed',
                new PasswordWithinHashLimit,
                Password::min(8)->letters()->numbers()->max(72),
            ],
        ];
    }
}
