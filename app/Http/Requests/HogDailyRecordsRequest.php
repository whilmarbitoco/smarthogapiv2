<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HogDailyRecordsRequest extends FormRequest
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
            'hog_id' => ['required', 'exists:hogs,id'],
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'weight' => ['required', 'numeric'],
            'feed_consumed' => ['required', 'numeric'],
            'health_status' => ['required', 'string', 'max:255'],
            'temperature' => ['required', 'numeric'],
            'activity_level' => ['required', 'string', 'max:255'],
            'notes' => ['required', 'string', 'max:255'],
            'recorded_date' => ['required', 'date'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'hog_id.required' => 'The hog ID is required.',
            'hog_id.exists' => 'The selected hog does not exist.',
            'hog_pen_id.required' => 'The hog pen ID is required.',
            'hog_pen_id.exists' => 'The selected hog pen does not exist.',
            'recorded_date.required' => 'The recorded date is required.',
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
