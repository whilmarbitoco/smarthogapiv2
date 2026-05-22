<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedingPredictionsRequest extends FormRequest
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
            'ml_model_id' => ['required', 'exists:ml_models,id'],
            'predicted_feed_amount' => ['required', 'numeric'],
            'confidence_score' => ['required', 'numeric'],
            'model_used' => ['nullable', 'string', 'max:255'],
            'confidence_level' => ['nullable', 'string', 'max:255'],
            'confidence_reason' => ['nullable', 'string'],
            'feed_recommendation' => ['nullable', 'array'],
            'feed_totals' => ['nullable', 'array'],
            'weight_trend' => ['nullable', 'array'],
            'pen_status' => ['nullable', 'array'],
            'warnings' => ['nullable', 'array'],
            'alerts' => ['nullable', 'array'],
            'suggestions' => ['nullable', 'array'],
            'fastapi_response' => ['nullable', 'array'],
            'predicted_at' => ['nullable', 'date'],
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
            'ml_model_id.required' => 'The ML model ID is required.',
            'ml_model_id.exists' => 'The selected ML model does not exist.',
            'predicted_feed_amount.required' => 'The predicted feed amount is required.',
            'confidence_score.required' => 'The confidence score is required.',
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
