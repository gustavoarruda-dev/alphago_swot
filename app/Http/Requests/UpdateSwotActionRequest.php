<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSwotActionRequest extends FormRequest
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
            'strategic_action' => ['sometimes', 'string', 'max:5000'],
            'title' => ['sometimes', 'string', 'max:5000'],
            'swot_link' => ['sometimes', 'nullable', 'url', 'max:5000'],
            'period' => ['sometimes', 'nullable', 'string', 'max:64'],
            'kpi' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:128'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
