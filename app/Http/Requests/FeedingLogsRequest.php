<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedingLogsRequest extends FormRequest
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
            'feeder_id' => ['required', 'exists:feeders,id'],
            'pen_id' => ['required', 'exists:hog_pens,id'],
            'feed_amount_given' => ['required', 'numeric'],
            'triggered' => ['required', 'string', 'max:255'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'feeder_id.required' => 'The feeder ID is required.',
            'feeder_id.exists' => 'The selected feeder does not exist.',
            'pen_id.required' => 'The pen ID is required.',
            'pen_id.exists' => 'The selected hog pen does not exist.',
            'feed_amount_given.required' => 'The feed amount given is required.',
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
