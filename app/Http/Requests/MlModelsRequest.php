<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MlModelsRequest extends FormRequest
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
            'model_name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:255'],
            'accuracy_score' => ['required', 'numeric'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'model_name.required' => 'The model name is required.',
            'version.required' => 'The model version is required.',
            'accuracy_score.required' => 'The accuracy score is required.',
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
