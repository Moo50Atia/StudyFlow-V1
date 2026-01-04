<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lecture_id' => 'required|exists:lectures,id',
            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'idea_text' => 'nullable|string',
            'solution_images' => 'nullable|array|max:10',
            'solution_images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'solution_explanation' => 'nullable|string',
            'dynamic_view_link' => 'nullable|url|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'lecture_id.required' => 'Please select a lecture.',
            'lecture_id.exists' => 'The selected lecture does not exist.',
            'question_image.image' => 'The question file must be an image.',
            'question_image.mimes' => 'The question image must be a JPEG, PNG, JPG, or GIF.',
            'question_image.max' => 'The question image must not exceed 5MB.',
            'solution_images.array' => 'Solution images must be an array.',
            'solution_images.max' => 'You can upload a maximum of 10 solution images.',
            'solution_images.*.image' => 'Each solution file must be an image.',
            'solution_images.*.mimes' => 'Each solution image must be a JPEG, PNG, JPG, or GIF.',
            'solution_images.*.max' => 'Each solution image must not exceed 5MB.',
            'dynamic_view_link.url' => 'The dynamic view link must be a valid URL.',
        ];
    }
}
