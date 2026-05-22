<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PredictionCacheRequest extends FormRequest
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
        $cacheId = $this->route('prediction_cache')?->id ?? $this->route('prediction_cache');

        $rules = [
            'prediction_type' => ['required', 'string', 'max:255'],
            'pen_id' => ['required', 'exists:hog_pens,id'],
            'cache_key' => ['required', 'string', 'max:255', Rule::unique('prediction_cache', 'cache_key')->ignore($cacheId)],
            'data' => ['required', 'array'],
            'expires_at' => ['nullable', 'date'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'prediction_type.required' => 'The prediction type is required.',
            'pen_id.required' => 'The pen ID is required.',
            'pen_id.exists' => 'The selected hog pen does not exist.',
            'cache_key.required' => 'The cache key is required.',
            'cache_key.unique' => 'The cache key has already been taken.',
            'data.required' => 'The cache data is required.',
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
