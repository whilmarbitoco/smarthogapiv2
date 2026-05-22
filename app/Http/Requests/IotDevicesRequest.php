<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IotDevicesRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:255'],
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'api_provider' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'external_provider' => ['nullable', 'string', 'max:255'],
            'external_device_id' => ['nullable', 'string', 'max:255'],
            'external_metadata' => ['nullable', 'array'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'type.required' => 'The device type is required.',
            'hog_pen_id.required' => 'The hog pen ID is required.',
            'hog_pen_id.exists' => 'The selected hog pen does not exist.',
            'api_provider.required' => 'The API provider is required.',
            'status.required' => 'The device status is required.',
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
