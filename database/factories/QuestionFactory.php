<?php

namespace Database\Factories;

use App\Models\Lecture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ideas = [
            'Apply the fundamental theorem to solve this problem.',
            'Use substitution method for simplification.',
            'Consider boundary conditions carefully.',
            'Break down the problem into smaller parts.',
            'Look for patterns in the given data.',
            'Apply the inverse operation.',
            'Use graphical representation for better understanding.',
        ];

        return [
            'lecture_id' => Lecture::factory(),
            'question_image' => 'questions/placeholder_question_' . fake()->numberBetween(1, 10) . '.png',
            'idea_text' => fake()->randomElement($ideas),
            'solution_images' => [
                'questions/placeholder_solution_' . fake()->numberBetween(1, 10) . '.png',
                'questions/placeholder_solution_' . fake()->numberBetween(1, 10) . '.png'
            ],
            'solution_explanation' => fake()->paragraph(2),
            'dynamic_view_link' => 'https://docs.google.com/document/d/' . fake()->uuid() . '/edit',
        ];
    }
}
