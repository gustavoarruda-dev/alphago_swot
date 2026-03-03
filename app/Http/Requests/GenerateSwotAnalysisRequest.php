<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateSwotAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuid' => ['nullable', 'string', 'max:64'],
            'analysis_title' => ['nullable', 'string', 'max:255'],
            'analysis_run_id' => ['nullable', 'string', 'max:128'],
            'trend_analysis_run_id' => ['nullable', 'string', 'max:128'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'segment' => ['nullable', 'string', 'max:64'],
            'objective' => ['nullable', 'string', 'max:500'],
            'trend_summary' => ['nullable', 'string', 'max:5000'],
            'analysis_prompt' => ['required', 'string', 'max:30000'],
            'filters' => ['nullable', 'array'],
            'filters.start_date' => ['nullable', 'string', 'max:32'],
            'filters.end_date' => ['nullable', 'string', 'max:32'],
            'filters.comparison_mode' => ['nullable', 'string', 'max:32'],
            'filters.comparison_start_date' => ['nullable', 'string', 'max:32'],
            'filters.comparison_end_date' => ['nullable', 'string', 'max:32'],
            'filters.analysis_run_id' => ['nullable', 'string', 'max:128'],
            'filters.view_mode' => ['nullable', 'string', 'max:32'],
            'filters.sectors' => ['nullable', 'array'],
            'filters.sectors.*' => ['string', 'max:128'],
            'filters.sources' => ['nullable', 'array'],
            'filters.sources.*' => ['string', 'max:128'],
            'filters.tags' => ['nullable', 'array'],
            'filters.tags.*' => ['string', 'max:128'],
            'filters.expressions' => ['nullable', 'array'],
            'filters.expressions.*' => ['string', 'max:255'],
        ];
    }
}
