<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class MobileRefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'refresh_token' => ['required', 'string', 'min:80', 'max:512'],
            'device_id' => ['required', 'uuid'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
