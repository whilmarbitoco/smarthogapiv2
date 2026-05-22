<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SensorsRequest extends FormRequest
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
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'sensor_type' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'exists:iot_devices,id'],
            'status' => ['required', 'string', 'max:255'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'hog_pen_id.required' => 'The hog pen ID is required.',
            'hog_pen_id.exists' => 'The selected hog pen does not exist.',
            'sensor_type.required' => 'The sensor type is required.',
            'device_id.required' => 'The device ID is required.',
            'device_id.exists' => 'The selected device does not exist.',
            'status.required' => 'The sensor status is required.',
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
