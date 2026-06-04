<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FarmsRequest extends FormRequest
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
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'name' => ['sometimes', 'required_without:location', 'string', 'max:255'],
            'location' => [
                'required_without:name',
                'string',
                'max:255',
                Rule::unique('farms', 'location')
                    ->where('user_id', auth()->id())
                    ->ignore($this->route('farm')?->id),
            ],
            'timezone' => ['sometimes', 'string', 'max:255'],
            'imageUrl' => ['nullable', 'url', 'max:2048'],
            'external_provider' => ['nullable', 'string', 'max:255'],
            'external_home_id' => ['nullable', 'string', 'max:255'],
            'external_metadata' => ['nullable', 'array'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'The user ID is required.',
            'user_id.exists' => 'The selected user does not exist.',
            'location.required' => 'The farm location is required.',
            'location.required_without' => 'The farm location is required when a Sinric home name is not provided.',
            'location.unique' => 'A farm with this location already exists for your account.',
            'name.required_without' => 'The Sinric home name is required when a farm location is not provided.',
            'timezone.required' => 'The farm timezone is required.',
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
