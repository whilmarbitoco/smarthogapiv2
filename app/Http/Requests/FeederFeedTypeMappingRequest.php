<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeederFeedTypeMappingRequest extends FormRequest
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
        $mappingId = $this->route('feeder_feed_type_mapping')?->id ?? $this->route('feeder_feed_type_mapping');

        $rules = [
            'feeder_id' => ['required', 'exists:feeders,id'],
            'feed_type' => [
                'required',
                'string',
                'max:255',
                Rule::unique('feeder_feed_type_mapping', 'feed_type')
                    ->where(fn ($query) => $query->where('feeder_id', $this->input('feeder_id')))
                    ->ignore($mappingId),
            ],
            'relay_pin' => ['nullable', 'integer'],
            'max_duration_seconds' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
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
            'feed_type.required' => 'The feed type is required.',
            'feed_type.unique' => 'This feed type is already mapped to the selected feeder.',
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
