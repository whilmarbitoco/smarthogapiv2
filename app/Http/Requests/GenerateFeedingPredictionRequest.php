<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateFeedingPredictionRequest extends FormRequest
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
        return [
            'hog_pen_id' => ['required', 'exists:hog_pens,id'],
            'force_refresh' => ['sometimes', 'boolean'],
            'feeding_frequency' => ['sometimes', 'integer', 'min:2'],
            'feeding_times' => ['sometimes', 'array', 'size:3'],
            'feeding_times.*' => ['required', 'string', 'max:20'],
            'schedule_type' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'hog_pen_id.required' => 'The hog pen ID is required.',
            'hog_pen_id.exists' => 'The selected hog pen does not exist.',
            'feeding_frequency.min' => 'The feeding frequency must be at least 2.',
            'feeding_times.size' => 'Exactly three feeding times are required.',
        ];
    }
}
