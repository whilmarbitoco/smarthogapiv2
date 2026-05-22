<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceCommandsRequest extends FormRequest
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
            'iot_device_id' => ['required', 'exists:iot_devices,id'],
            'action' => ['required', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:pending,processing,completed,failed'],
            'executed_at' => ['nullable', 'date'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'iot_device_id.required' => 'The IoT device ID is required.',
            'iot_device_id.exists' => 'The selected IoT device does not exist.',
            'action.required' => 'The command action is required.',
            'status.in' => 'Status must be pending, processing, completed or failed.',
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
