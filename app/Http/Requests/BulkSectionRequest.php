<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'sections' => 'required|array|min:1',
            'sections.*.title' => 'required|string|max:255',
            'sections.*.quick_summary' => 'nullable|string',
            'sections.*.core_concept' => 'nullable|string',
            'sections.*.egyptian_explain' => 'nullable|string',
            'sections.*.formulas' => 'nullable|string',
            'sections.*.real_life' => 'nullable|string',
            'sections.*.dynamic_view_link' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'sections.required' => 'At least one section is required.',
            'sections.*.title.required' => 'Each section must have a title.',
            'sections.*.dynamic_view_link.url' => 'Dynamic view link must be a valid URL.',
        ];
    }
}
