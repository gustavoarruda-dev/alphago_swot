<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSwotFactorRequest extends FormRequest
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
            'quadrant' => ['required', 'string', 'in:strengths,opportunities,weaknesses,threats,strength,opportunity,weakness,threat'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tag' => ['nullable', 'string', 'max:128'],
            'priority' => ['nullable', 'string', 'max:32'],
            'impact' => ['nullable', 'string', 'max:32'],
            'dimension' => ['nullable', 'string', 'max:128'],
        ];
    }
}
