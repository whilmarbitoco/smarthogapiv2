<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DailyFarmReportsRequest extends FormRequest
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
        $reportId = $this->route('daily_farm_report')?->id ?? $this->route('daily_farm_report');

        $rules = [
            'farm_id' => ['required', 'exists:farms,id'],
            'total_feed_consumed' => ['required', 'numeric'],
            'total_hogs' => ['required', 'integer'],
            'avg_weight' => ['required', 'numeric'],
            'mortality_count' => ['required', 'numeric'],
            'report_date' => [
                'required',
                'date',
                Rule::unique('daily_farm_reports', 'report_date')
                    ->where(fn ($query) => $query->where('farm_id', $this->input('farm_id')))
                    ->ignore($reportId),
            ],
            'active_pens' => ['sometimes', 'integer'],
            'avg_temperature' => ['sometimes', 'numeric'],
            'avg_humidity' => ['sometimes', 'numeric'],
            'alerts_triggered' => ['sometimes', 'integer'],
            'sick_hogs' => ['sometimes', 'integer'],
            'avg_weekly_weight_gain' => ['sometimes', 'numeric'],
        ];

        return $this->isMethod('put') || $this->isMethod('patch')
            ? $this->partialRules($rules)
            : $rules;
    }

    public function messages(): array
    {
        return [
            'farm_id.required' => 'The farm ID is required.',
            'farm_id.exists' => 'The selected farm does not exist.',
            'report_date.required' => 'The report date is required.',
            'report_date.unique' => 'A report already exists for this farm and report date.',
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
