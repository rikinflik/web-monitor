<?php

namespace App\Http\Requests;

use App\Rules\NoPrivateUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMonitorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => ['required', 'url', 'max:255', new NoPrivateUrl()],
            'interval' => 'required|integer|min:60',
            'timeout' => 'required|integer|min:1|max:60',
            'expected_status_code' => 'required|integer|min:100|max:599',
            'keyword' => 'nullable|string|max:255',
            'webhook_url' => ['nullable', 'url', 'max:255', new NoPrivateUrl()],
            'basic_auth_user' => 'nullable|string|max:255',
            'basic_auth_password' => 'nullable|string|max:255',
        ];
    }
}
