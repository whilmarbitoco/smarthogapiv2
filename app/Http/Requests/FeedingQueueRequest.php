<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedingQueueRequest extends FormRequest
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
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'feed_type' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'actual_feed_time' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'max:255'],
            'duration_seconds' => ['sometimes', 'integer', 'min:1'],
            'amount_dispensed' => ['nullable', 'numeric'],
            'error_message' => ['nullable', 'string'],
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
            'hog_pen_id.required' => 'The hog pen ID is required.',
            'hog_pen_id.exists' => 'The selected hog pen does not exist.',
            'scheduled_at.required' => 'The scheduled feed time is required.',
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
