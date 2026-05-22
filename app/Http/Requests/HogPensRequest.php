<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HogPensRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'integer'],
            'external_provider' => ['nullable', 'string', 'max:255'],
            'external_room_id' => ['nullable', 'string', 'max:255'],
            'external_metadata' => ['nullable', 'array'],
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
            'name.required' => 'The hog pen name is required.',
            'capacity.required' => 'The hog pen capacity is required.',
            'status.required' => 'The hog pen status is required.',
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
