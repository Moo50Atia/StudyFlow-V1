<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'questions' => 'nullable|array',
            'questions.*.idea' => 'required_with:questions|string',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.dynamic_view_link' => 'nullable|url',

            'exam_questions' => 'nullable|array',
            'exam_questions.*.idea' => 'required_with:exam_questions|string',
            'exam_questions.*.explanation' => 'nullable|string',
            'exam_questions.*.dynamic_view_link' => 'nullable|url',

            'dynamic_view_link' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'questions.*.idea.required_with' => 'Each question must have an idea.',
            'exam_questions.*.idea.required_with' => 'Each exam question must have an idea.',
        ];
    }
}
