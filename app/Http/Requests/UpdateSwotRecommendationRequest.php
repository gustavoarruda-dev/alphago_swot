<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSwotRecommendationRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:500'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:32'],
            'period_label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'period' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
