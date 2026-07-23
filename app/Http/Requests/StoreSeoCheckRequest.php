<?php

namespace App\Http\Requests;

use App\Rules\NoPrivateUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreSeoCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by creating through $request->user()->monitors().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => ['required', 'url', 'max:255', new NoPrivateUrl()],
        ];
    }
}
