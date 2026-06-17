<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FeedingQueueRequest extends FormRequest
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
        return [
            'feed_quantity' => ['sometimes', 'numeric', 'min:0.1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'feed_quantity.numeric' => 'Feed quantity must be a number.',
            'feed_quantity.min' => 'Feed quantity must be at least 0.1.',
            'feed_quantity.max' => 'Feed quantity cannot exceed 100.',
        ];
    }
}
