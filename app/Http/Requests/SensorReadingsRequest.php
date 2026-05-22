<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SensorReadingsRequest extends FormRequest
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
            'sensor_id' => ['required', 'exists:sensors,id'],
            'value' => ['required', 'numeric'],
            'unit' => ['required', 'string', 'max:255'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'sensor_id.required' => 'The sensor ID is required.',
            'sensor_id.exists' => 'The selected sensor does not exist.',
            'value.required' => 'The sensor value is required.',
            'unit.required' => 'The sensor unit is required.',
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
