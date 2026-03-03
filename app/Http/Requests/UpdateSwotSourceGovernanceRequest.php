<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSwotSourceGovernanceRequest extends FormRequest
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
            'analysis_run_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'source_name' => ['sometimes', 'string', 'max:255'],
            'source_origin' => ['sometimes', 'string', 'max:64'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source_category' => ['sometimes', 'nullable', 'string', 'max:128'],
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'is_priority' => ['sometimes', 'boolean'],
            'extra_metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
