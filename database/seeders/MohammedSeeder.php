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

class MohammedSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin Account
        $admin = User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Mohammed Admin',
                'email' => 'admin@test.com',
                'password' => Hash::make('Password'),
                'role' => 'admin',
            ]
        );

        // Teacher Account
        $teacher = User::updateOrCreate(
            ['email' => 'teacher@test.com'],
            [
                'name' => 'Mohammed Teacher',
                'email' => 'teacher@test.com',
                'password' => Hash::make('Password'),
                'role' => 'teacher',
            ]
        );

        // Student Account
        $student = User::updateOrCreate(
            ['email' => 'student@test.com'],
            [
                'name' => 'Mohammed Student',
                'email' => 'student@test.com',
                'password' => Hash::make('Password'),
                'role' => 'student',
            ]
        );

        // Create Subjects
        $mathSubject = Subject::updateOrCreate(
            ['name' => 'Mathematics'],
            ['name' => 'Mathematics', 'description' => 'Advanced Mathematics including Calculus and Algebra']
        );

        $physicsSubject = Subject::updateOrCreate(
            ['name' => 'Physics'],
            ['name' => 'Physics', 'description' => 'Classical and Modern Physics']
        );

        $chemistrySubject = Subject::updateOrCreate(
            ['name' => 'Chemistry'],
            ['name' => 'Chemistry', 'description' => 'Organic and Inorganic Chemistry']
        );

        $biologySubject = Subject::updateOrCreate(
            ['name' => 'Biology'],
            ['name' => 'Biology', 'description' => 'Life Sciences and Anatomy']
        );

        $computerSubject = Subject::updateOrCreate(
            ['name' => 'Computer Science'],
            ['name' => 'Computer Science', 'description' => 'Programming and Software Development']
        );

        // Create Lectures for subjects with all fields
        $mathLecture = Lecture::updateOrCreate(
            ['title' => 'Introduction to Calculus', 'subject_id' => $mathSubject->id],
            [
                'title' => 'Introduction to Calculus',
                'subject_id' => $mathSubject->id,
                'summary' => 'Basic concepts of differential calculus including limits, derivatives, and their applications.',
                'pdf_path' => 'lectures/calculus_intro.pdf',
            ]
        );

        $physicsLecture = Lecture::updateOrCreate(
            ['title' => 'Newtonian Mechanics', 'subject_id' => $physicsSubject->id],
            [
                'title' => 'Newtonian Mechanics',
                'subject_id' => $physicsSubject->id,
                'summary' => 'Laws of motion and forces including Newton\'s three laws.',
                'pdf_path' => 'lectures/newtonian_mechanics.pdf',
            ]
        );

        $chemistryLecture = Lecture::updateOrCreate(
            ['title' => 'Organic Compounds', 'subject_id' => $chemistrySubject->id],
            [
                'title' => 'Organic Compounds',
                'subject_id' => $chemistrySubject->id,
                'summary' => 'Introduction to organic chemistry and carbon-based compounds.',
                'pdf_path' => 'lectures/organic_compounds.pdf',
            ]
        );

        $biologyLecture = Lecture::updateOrCreate(
            ['title' => 'Cell Biology', 'subject_id' => $biologySubject->id],
            [
                'title' => 'Cell Biology',
                'subject_id' => $biologySubject->id,
                'summary' => 'Structure and function of cells including organelles.',
                'pdf_path' => 'lectures/cell_biology.pdf',
            ]
        );

        $computerLecture = Lecture::updateOrCreate(
            ['title' => 'Introduction to Programming', 'subject_id' => $computerSubject->id],
            [
                'title' => 'Introduction to Programming',
                'subject_id' => $computerSubject->id,
                'summary' => 'Basic programming concepts and problem-solving techniques.',
                'pdf_path' => 'lectures/intro_programming.pdf',
            ]
        );

        // Create Sections for each lecture
        $this->createSectionsForLecture($mathLecture, [
            ['title' => 'Limits and Continuity', 'quick_summary' => 'Understanding limits as the foundation of calculus.'],
            ['title' => 'Derivatives Basics', 'quick_summary' => 'Introduction to derivatives and differentiation rules.'],
        ]);

        $this->createSectionsForLecture($physicsLecture, [
            ['title' => 'Newton\'s First Law', 'quick_summary' => 'An object at rest stays at rest unless acted upon by a force.'],
            ['title' => 'Force and Acceleration', 'quick_summary' => 'Understanding F=ma and its applications.'],
        ]);

        $this->createSectionsForLecture($chemistryLecture, [
            ['title' => 'Hydrocarbons', 'quick_summary' => 'Basic hydrocarbon structures and naming.'],
            ['title' => 'Functional Groups', 'quick_summary' => 'Common functional groups in organic chemistry.'],
        ]);

        $this->createSectionsForLecture($biologyLecture, [
            ['title' => 'Cell Membrane', 'quick_summary' => 'Structure and function of the plasma membrane.'],
            ['title' => 'Organelles', 'quick_summary' => 'Understanding mitochondria, nucleus, and other organelles.'],
        ]);

        $this->createSectionsForLecture($computerLecture, [
            ['title' => 'Variables and Data Types', 'quick_summary' => 'Understanding how to store and manipulate data.'],
            ['title' => 'Control Structures', 'quick_summary' => 'If statements, loops, and conditionals.'],
        ]);

        // Create Questions for each lecture
        $this->createQuestionsForLecture($mathLecture, [
            ['idea_text' => 'Find the limit of (x²-1)/(x-1) as x approaches 1.', 'solution_explanation' => 'Factor the numerator to get (x+1)(x-1)/(x-1) = x+1. At x=1, answer is 2.'],
            ['idea_text' => 'Calculate the derivative of f(x) = 3x² + 2x - 5.', 'solution_explanation' => 'Using power rule: f\'(x) = 6x + 2.'],
        ]);

        $this->createQuestionsForLecture($physicsLecture, [
            ['idea_text' => 'A 10kg object accelerates at 5 m/s². Find the force.', 'solution_explanation' => 'Using F=ma: F = 10 × 5 = 50 Newtons.'],
            ['idea_text' => 'An object at rest on a frictionless surface. What force is needed?', 'solution_explanation' => 'Any force will cause acceleration as there is no friction.'],
        ]);

        // Create Exam Questions for each lecture
        $this->createExamQuestionsForLecture($mathLecture, [
            ['idea' => 'Evaluate the integral of sin(x)dx.', 'explanation' => 'The integral of sin(x) is -cos(x) + C.'],
            ['idea' => 'Find the maximum value of f(x) = -x² + 4x + 5.', 'explanation' => 'Take derivative, set to 0. f\'(x) = -2x + 4 = 0, so x = 2. f(2) = 9.'],
        ]);

        $this->createExamQuestionsForLecture($physicsLecture, [
            ['idea' => 'Calculate the work done lifting a 5kg object 10m high.', 'explanation' => 'Work = mgh = 5 × 9.8 × 10 = 490 Joules.'],
        ]);

        // Assign Teacher Permissions
        TeacherPermission::updateOrCreate(
            ['teacher_id' => $teacher->id, 'subject_id' => $mathSubject->id],
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $mathSubject->id,
                'lecture_id' => $mathLecture->id,
            ]
        );

        TeacherPermission::updateOrCreate(
            ['teacher_id' => $teacher->id, 'subject_id' => $physicsSubject->id],
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $physicsSubject->id,
                'lecture_id' => $physicsLecture->id,
            ]
        );

        $this->command->info('');
        $this->command->info('✅ Mohammed Seeder completed!');
        $this->command->info('');
        $this->command->info('📧 Test Accounts Created:');
        $this->command->info('┌───────────────────────────────────────────────────┐');
        $this->command->info('│  Role     │  Email              │  Password      │');
        $this->command->info('├───────────────────────────────────────────────────┤');
        $this->command->info('│  Admin    │  admin@test.com     │  Password      │');
        $this->command->info('│  Teacher  │  teacher@test.com   │  Password      │');
        $this->command->info('│  Student  │  student@test.com   │  Password      │');
        $this->command->info('└───────────────────────────────────────────────────┘');
        $this->command->info('');
        $this->command->info('📚 Data Created:');
        $this->command->info('  - 5 Subjects with Lectures');
        $this->command->info('  - 10 Sections (2 per lecture)');
        $this->command->info('  - 4 Questions');
        $this->command->info('  - 3 Exam Questions');
        $this->command->info('');
        $this->command->info('👨‍🏫 Teacher Permissions:');
        $this->command->info('  ✅ Can CRUD: Mathematics, Physics');
    }

    /**
     * Create sections for a lecture
     */
    private function createSectionsForLecture(Lecture $lecture, array $sectionsData): void
    {
        foreach ($sectionsData as $data) {
            Section::updateOrCreate(
                ['lecture_id' => $lecture->id, 'title' => $data['title']],
                [
                    'lecture_id' => $lecture->id,
                    'title' => $data['title'],
                    'quick_summary' => $data['quick_summary'],
                    'core_concept' => 'This is the core concept for ' . $data['title'],
                    'dynamic_view_link' => 'https://docs.google.com/document/d/' . uniqid() . '/edit',
                ]
            );
        }
    }

    /**
     * Create questions for a lecture
     */
    private function createQuestionsForLecture(Lecture $lecture, array $questionsData): void
    {
        $i = 1;
        foreach ($questionsData as $data) {
            Question::updateOrCreate(
                ['lecture_id' => $lecture->id, 'idea_text' => $data['idea_text']],
                [
                    'lecture_id' => $lecture->id,
                    'question_image' => 'questions/question_placeholder_' . $i . '.png',
                    'idea_text' => $data['idea_text'],
                    'solution_images' => ['questions/solution_placeholder_' . $i . '.png'],
                    'solution_explanation' => $data['solution_explanation'],
                    'dynamic_view_link' => 'https://docs.google.com/document/d/' . uniqid() . '/edit',
                ]
            );
            $i++;
        }
    }

    /**
     * Create exam questions for a lecture
     */
    private function createExamQuestionsForLecture(Lecture $lecture, array $examQuestionsData): void
    {
        $i = 1;
        foreach ($examQuestionsData as $data) {
            ExamQuestion::updateOrCreate(
                ['lecture_id' => $lecture->id, 'idea' => $data['idea']],
                [
                    'lecture_id' => $lecture->id,
                    'question_image' => 'exam_questions/exam_placeholder_' . $i . '.png',
                    'idea' => $data['idea'],
                    'solution_image' => 'exam_questions/solution_placeholder_' . $i . '.png',
                    'explanation' => $data['explanation'],
                    'dynamic_view_link' => 'https://docs.google.com/document/d/' . uniqid() . '/edit',
                ]
            );
            $i++;
        }
    }
}
