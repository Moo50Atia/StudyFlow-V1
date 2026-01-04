<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Subject;
use App\Models\Lecture;
use App\Models\Section;
use App\Models\Question;
use App\Models\ExamQuestion;
use App\Models\TeacherPermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create Admin User
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Create Teachers
        $teachers = collect();
        $teachers->push(User::factory()->create([
            'name' => 'Dr. Ahmed Hassan',
            'email' => 'teacher1@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
        ]));
        $teachers->push(User::factory()->create([
            'name' => 'Prof. Sarah Mohamed',
            'email' => 'teacher2@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
        ]));
        $teachers->push(User::factory()->create([
            'name' => 'Dr. Omar Ali',
            'email' => 'teacher3@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
        ]));

        // Create Students
        User::factory(10)->student()->create();

        // Create Subjects
        $subjectsData = [
            ['name' => 'Mathematics', 'description' => 'Comprehensive study of numbers, algebra, calculus, and geometry.'],
            ['name' => 'Physics', 'description' => 'Study of matter, energy, and the fundamental forces of nature.'],
            ['name' => 'Chemistry', 'description' => 'Study of substances, their properties, and chemical reactions.'],
            ['name' => 'Biology', 'description' => 'Study of living organisms and their vital processes.'],
            ['name' => 'Computer Science', 'description' => 'Study of computation, algorithms, and programming.'],
        ];

        $subjects = collect();
        foreach ($subjectsData as $subjectData) {
            $subjects->push(Subject::create($subjectData));
        }

        // Create Lectures for each Subject
        $lectureCount = 1;
        foreach ($subjects as $subject) {
            $numLectures = rand(3, 4);
            for ($i = 1; $i <= $numLectures; $i++) {
                $lecture = Lecture::create([
                    'subject_id' => $subject->id,
                    'title' => "Lecture {$lectureCount}: " . $this->getLectureTitle($i),
                    'pdf_path' => null,
                    'summary' => fake()->paragraph(3),
                ]);
                $lectureCount++;

                // Create 2-3 Sections per Lecture
                $numSections = rand(2, 3);
                for ($j = 1; $j <= $numSections; $j++) {
                    Section::create([
                        'lecture_id' => $lecture->id,
                        'title' => $this->getSectionTitle($j),
                        'quick_summary' => fake()->sentence(8),
                        'core_concept' => fake()->sentence(10),
                        'dynamic_view_link' => rand(0, 1) ? fake()->url() : null,
                    ]);
                }

                // Create 2-4 Questions per Lecture
                $numQuestions = rand(2, 4);
                for ($k = 1; $k <= $numQuestions; $k++) {
                    Question::create([
                        'lecture_id' => $lecture->id,
                        'question_image' => null,
                        'idea_text' => $this->getIdeaText(),
                        'solution_images' => null,
                        'solution_explanation' => fake()->paragraph(2),
                        'dynamic_view_link' => rand(0, 1) ? fake()->url() : null,
                    ]);
                }

                // Create 2-3 Exam Questions per Lecture
                $numExamQuestions = rand(2, 3);
                for ($l = 1; $l <= $numExamQuestions; $l++) {
                    ExamQuestion::create([
                        'lecture_id' => $lecture->id,
                        'question_image' => null,
                        'idea' => $this->getExamIdea(),
                        'solution_image' => null,
                        'explanation' => fake()->paragraph(3),
                        'dynamic_view_link' => rand(0, 1) ? fake()->url() : null,
                    ]);
                }
            }
        }

        // Create Teacher Permissions
        // Assign each teacher to specific subjects and their lectures
        $allLectures = Lecture::with('subject')->get();

        // Teacher 1: Mathematics and Physics
        $teacher1Subjects = $subjects->whereIn('name', ['Mathematics', 'Physics']);
        foreach ($teacher1Subjects as $subject) {
            $subjectLectures = $allLectures->where('subject_id', $subject->id);
            foreach ($subjectLectures as $lecture) {
                TeacherPermission::create([
                    'teacher_id' => $teachers[0]->id,
                    'subject_id' => $subject->id,
                    'lecture_id' => $lecture->id,
                ]);
            }
        }

        // Teacher 2: Chemistry and Biology
        $teacher2Subjects = $subjects->whereIn('name', ['Chemistry', 'Biology']);
        foreach ($teacher2Subjects as $subject) {
            $subjectLectures = $allLectures->where('subject_id', $subject->id);
            foreach ($subjectLectures as $lecture) {
                TeacherPermission::create([
                    'teacher_id' => $teachers[1]->id,
                    'subject_id' => $subject->id,
                    'lecture_id' => $lecture->id,
                ]);
            }
        }

        // Teacher 3: Computer Science
        $teacher3Subjects = $subjects->whereIn('name', ['Computer Science']);
        foreach ($teacher3Subjects as $subject) {
            $subjectLectures = $allLectures->where('subject_id', $subject->id);
            foreach ($subjectLectures as $lecture) {
                TeacherPermission::create([
                    'teacher_id' => $teachers[2]->id,
                    'subject_id' => $subject->id,
                    'lecture_id' => $lecture->id,
                ]);
            }
        }
        $this->call([
            MohammedSeeder::class,
        ]);
    }

    private function getLectureTitle(int $index): string
    {
        $titles = [
            'Introduction to the Subject',
            'Fundamental Concepts',
            'Advanced Techniques',
            'Problem Solving Strategies',
            'Practical Applications',
        ];
        return $titles[$index - 1] ?? $titles[0];
    }

    private function getSectionTitle(int $index): string
    {
        $titles = [
            'Key Definitions',
            'Core Concepts',
            'Examples and Exercises',
            'Summary Points',
        ];
        return $titles[$index - 1] ?? $titles[0];
    }

    private function getIdeaText(): string
    {
        $ideas = [
            'Apply the fundamental theorem to solve this problem.',
            'Use substitution method for simplification.',
            'Consider boundary conditions carefully.',
            'Break down the problem into smaller parts.',
            'Look for patterns in the given data.',
        ];
        return $ideas[array_rand($ideas)];
    }

    private function getExamIdea(): string
    {
        $ideas = [
            'This is a classic exam problem testing core concepts.',
            'Focus on the relationship between variables.',
            'Show all steps for full marks.',
            'This tests understanding of theoretical foundations.',
        ];
        return $ideas[array_rand($ideas)];
    }
}
