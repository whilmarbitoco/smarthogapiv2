<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WebHookLogsRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:255'],
            'event' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'status' => ['sometimes', 'in:sent,failed'],
            'error' => ['nullable', 'string'],
            'farm_id' => ['nullable', 'exists:farms,id'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'url.required' => 'The webhook URL is required.',
            'url.url' => 'The webhook URL must be valid.',
            'event.required' => 'The webhook event is required.',
            'payload.required' => 'The webhook payload is required.',
            'status.in' => 'Status must be sent or failed.',
            'farm_id.exists' => 'The selected farm does not exist.',
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
