<?php

namespace App\Http\Requests;

use App\Enums\HogBreed;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'time' => ['required', 'date'],
            'feed_amount' => ['required', 'numeric'],
            'feed_type' => ['nullable', 'string', 'max:255'],
            'breed' => ['required', 'string', Rule::in(HogBreed::values())],
            'mode' => ['sometimes', 'string', 'max:255'],
            'frequency' => ['sometimes', 'string', 'in:everyday,weekdays,weekends,custom'],
            'custom_days' => ['nullable', 'array'],
            'custom_days.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
            'feeding_times' => ['nullable', 'array'],
            'daily_feeding_count' => ['sometimes', 'integer', 'min:1'],
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
            'time.required' => 'The feeding time is required.',
            'feed_amount.required' => 'The feed amount is required.',
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
