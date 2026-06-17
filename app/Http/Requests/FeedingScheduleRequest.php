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
            'feeding_times.*' => ['string'],
            'daily_feeding_count' => ['sometimes', 'integer', 'min:1'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'feeding_times' => $this->normalizeFeedingTimes($this->input('feeding_times')),
        ]);
    }

    /**
     * Normalize feeding times to 24-hour H:i format.
     * Accepts "12:00 PM", "09:41 AM", "14:30", "14:30:00", etc.
     *
     * @param  mixed  $times
     * @return list<string>|null
     */
    private function normalizeFeedingTimes(mixed $times): ?array
    {
        if (! is_array($times) || $times === []) {
            return null;
        }

        $normalized = [];

        foreach ($times as $time) {
            $time = trim((string) $time);

            if ($time === '') {
                continue;
            }

            // Try H:i:s first, then H:i, then fallback for AM/PM
            foreach (['H:i:s', 'H:i', 'g:i A', 'g:iA', 'h:i A', 'h:iA', 'G:i'] as $format) {
                $parsed = \DateTime::createFromFormat($format, $time);

                if ($parsed !== false) {
                    $normalized[] = $parsed->format('H:i');
                    break;
                }
            }
        }

        return array_values(array_unique($normalized)) ?: null;
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
