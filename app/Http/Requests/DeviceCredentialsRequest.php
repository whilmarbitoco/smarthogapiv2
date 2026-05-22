<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $credentialId = $this->route('device_credential')?->id ?? $this->route('device_credential');

        $rules = [
            'user_id' => ['required', 'exists:users,id'],
            'iot_device_id' => ['nullable', 'exists:iot_devices,id'],
            'name' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:255', Rule::unique('device_credentials', 'api_key')->ignore($credentialId)],
            'secret' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'last_used_at' => ['nullable', 'date'],
            'revoked_at' => ['nullable', 'date'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'The user ID is required.',
            'user_id.exists' => 'The selected user does not exist.',
            'iot_device_id.exists' => 'The selected IoT device does not exist.',
            'api_key.required' => 'The API key is required.',
            'api_key.unique' => 'The API key has already been taken.',
            'secret.required' => 'The secret is required.',
        ];
    }

    private function partialRules(array $rules): array
    {
        foreach ($rules as $field => $fieldRules) {
            array_unshift($fieldRules, 'sometimes');
            $rules[$field] = $fieldRules;
        }

        return $rules;
    }
}
