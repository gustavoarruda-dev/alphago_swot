<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSwotSourceGovernanceRequest extends FormRequest
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
            'analysis_run_id' => ['nullable', 'string', 'max:128'],
            'source_name' => ['required', 'string', 'max:255'],
            'source_origin' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'string', 'max:5000'],
            'source_category' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'is_priority' => ['nullable', 'boolean'],
            'extra_metadata' => ['nullable', 'array'],
        ];
    }
}
