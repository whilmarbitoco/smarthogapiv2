<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AlertsRequest extends FormRequest
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
            'farm_id' => ['required', 'exists:farms,id'],
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'type' => ['required', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:1000'],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'status' => ['required', 'in:active,resolved'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'farm_id.required' => 'The farm ID is required.',
            'farm_id.exists' => 'The selected farm does not exist.',
            'hog_pen_id.required' => 'The hog pen ID is required.',
            'hog_pen_id.exists' => 'The selected hog pen does not exist.',
            'severity.in' => 'Severity must be low, medium, high or critical.',
            'status.in' => 'Status must be active or resolved.',
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
