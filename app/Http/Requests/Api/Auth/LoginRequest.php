<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\PasswordWithinHashLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', new PasswordWithinHashLimit],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['sometimes', 'nullable', 'string', 'in:ios,android,web,other'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:190'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }
}
