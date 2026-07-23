<?php

namespace App\Http\Requests\Api\Auth;

use App\Enums\DevicePlatform;
use Illuminate\Validation\Rule;

class MobileLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
