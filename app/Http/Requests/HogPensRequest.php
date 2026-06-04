<?php

namespace App\Http\Requests;

use App\Models\Farms;
use Illuminate\Foundation\Http\FormRequest;

class HogPensRequest extends FormRequest
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
            'farm_id' => ['required', 'exists:farms,id'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'imageUrl' => ['nullable', 'url', 'max:2048'],
            'external_provider' => ['nullable', 'string', 'max:255'],
            'external_room_id' => ['nullable', 'string', 'max:255'],
            'external_metadata' => ['nullable', 'array'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    protected function prepareForValidation(): void
    {
        $homeId = $this->input('homeId', $this->input('home_id'));

        if (! $this->has('farm_id') && is_string($homeId) && $homeId !== '' && auth()->id() !== null) {
            $farm = Farms::query()
                ->where('user_id', auth()->id())
                ->where('external_provider', 'sinric')
                ->where('external_home_id', $homeId)
                ->first();

            if ($farm instanceof Farms) {
                $this->merge(['farm_id' => $farm->id]);
            }
        }

        $roomId = $this->input('id', $this->input('roomId', $this->input('room_id')));

        if (! $this->has('external_room_id') && is_string($roomId) && $roomId !== '') {
            $this->merge(['external_room_id' => $roomId]);
        }
    }

    public function messages(): array
    {
        return [
            'farm_id.required' => 'The farm ID is required.',
            'farm_id.exists' => 'The selected farm does not exist.',
            'name.required' => 'The hog pen name is required.',
            'capacity.required' => 'The hog pen capacity is required.',
            'status.required' => 'The hog pen status is required.',
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
