<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HogsRequest extends FormRequest
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
            'ear_tag_id' => ['required', 'string', 'max:255'],
            'breed' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'string', 'max:255'],
            'current_age' => ['required', 'integer', 'min:0'],
            'weight_current' => ['required', 'numeric'],
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
            'ear_tag_id.required' => 'The ear tag ID is required.',
            'current_age.required' => 'The current age is required.',
            'weight_current.required' => 'The current weight is required.',
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
