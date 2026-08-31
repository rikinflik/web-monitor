<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * Keep validation errors out of the other profile forms' bags.
     *
     * @var string
     */
    protected $errorBag = 'updateNotificationPreferences';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the payload before validation.
     *
     * The monitor checkboxes are absent from the request when none are ticked,
     * which must be read as "an empty selection", not as "leave it untouched".
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'monitors' => array_values(array_unique((array) $this->input('monitors', []))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notify_mode' => ['required', Rule::in(User::NOTIFY_MODES)],
            'monitors' => ['array'],
            'monitors.*' => ['integer', 'exists:monitors,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monitors.*.exists' => __('One of the selected websites no longer exists.'),
        ];
    }
}
