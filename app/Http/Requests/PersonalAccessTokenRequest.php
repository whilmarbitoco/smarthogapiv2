<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalAccessTokenRequest extends FormRequest
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
        $tokenId = $this->route('personal_access_token')?->id ?? $this->route('personal_access_token');

        $rules = [
            'tokenable_type' => ['required', 'string', 'max:255'],
            'tokenable_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:64', Rule::unique('personal_access_tokens', 'token')->ignore($tokenId)],
            'abilities' => ['nullable', 'array'],
            'last_used_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'tokenable_type.required' => 'The tokenable type is required.',
            'tokenable_id.required' => 'The tokenable ID is required.',
            'name.required' => 'The token name is required.',
            'token.required' => 'The token is required.',
            'token.unique' => 'The token has already been taken.',
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
