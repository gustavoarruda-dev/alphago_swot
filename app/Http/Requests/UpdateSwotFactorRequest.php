<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSwotFactorRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:128'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:32'],
            'impact' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dimension' => ['sometimes', 'nullable', 'string', 'max:128'],
            'source_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_url' => ['sometimes', 'nullable', 'url', 'max:5000'],
        ];
    }
}
