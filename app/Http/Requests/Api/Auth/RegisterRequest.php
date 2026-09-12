<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', new PasswordWithinHashLimit, Password::min(8)->letters()->numbers()->max(72)],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['sometimes', 'nullable', 'string', 'in:ios,android,web,other'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:190'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'locale' => ['sometimes', 'string', 'in:en,vi'],
            'timezone' => ['sometimes', 'string', 'timezone'],
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
