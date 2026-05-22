<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceLogsRequest extends FormRequest
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
        $rules = [
            'device_id' => ['required', 'exists:iot_devices,id'],
            'action' => ['required', 'string', 'max:255'],
            'response' => ['required', 'string', 'max:255'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'device_id.required' => 'The device ID is required.',
            'device_id.exists' => 'The selected device does not exist.',
            'action.required' => 'The log action is required.',
            'response.required' => 'The log response is required.',
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
