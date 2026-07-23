<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChallengeVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'min:40', 'max:255'],
            'device_id' => ['sometimes', 'nullable', 'uuid'],
            'otp' => ['nullable', 'required_without:recovery_code', Rule::prohibitedIf($this->filled('recovery_code')), 'digits:6'],
            'recovery_code' => ['nullable', 'required_without:otp', Rule::prohibitedIf($this->filled('otp')), 'string', 'max:64'],
        ];
    }
}
