<?php

namespace App\Support;

use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Bridges existing controller projections into the React page catalog. */
class InertiaPage
{
    public static function render(string $screen, array $props = [], int $status = 200, array $headers = []): SymfonyResponse
    {
        $component = match ($screen) {
            'profile.edit' => 'Profile',
            'auth.login' => 'Auth/Login',
            'auth.register' => 'Auth/Register',
            'auth.forgot-password' => 'Auth/ForgotPassword',
            'auth.reset-password' => 'Auth/ResetPassword',
            'auth.confirm-password' => 'Auth/ConfirmPassword',
            'auth.verify-email' => 'Auth/VerifyEmail',

            // Teacher Intake & Workflow
            'v3.teacher.workspace' => 'Teacher/Workspace',
            'v3.teacher.pdf-uploads.index' => 'Teacher/PdfUploadIndex',
            'v3.teacher.pdf-uploads.show' => 'Teacher/PdfUploadShow',
            'v3.teacher.subject-runs.create' => 'Teacher/RunCreate',
            'v3.teacher.subject-runs.show' => 'Teacher/RunShow',
            'v3.teacher.subject-runs.scenes' => 'Teacher/RunScenes',
            'v3.teacher.cases.show' => 'Teacher/CaseShow',
            'v3.teacher.cases.progress' => 'Teacher/CaseProgress',
            'v3.teacher.cases.source-status' => 'Teacher/SourceStatus',
            'v3.teacher.prompts.status' => 'Teacher/PromptStatus',
            'v3.teacher.cases.conflict' => 'Teacher/CaseConflict',
            'v3.teacher.evidence.show' => 'Teacher/Evidence',
            'v3.teacher.evidence.approval' => 'Teacher/ApprovalEvidence',
            'v3.teacher.lessons.map' => 'Teacher/LessonMap',
            'v3.teacher.lessons.section' => 'Teacher/LessonSection',
            'v3.teacher.reviews.index' => 'Teacher/ReviewIndex',
            'v3.teacher.reviews.show' => 'Teacher/ReviewShow',
            'v3.teacher.reviews.approvals' => 'Teacher/Approvals',

            // Admin Operations & Settings
            'v3.admin.workspace' => 'Admin/Workspace',
            'v3.admin.accounts.index' => 'Admin/Accounts',
            'v3.admin.settings.show' => 'Admin/Settings',
            'v3.admin.settings.history' => 'Admin/SettingsHistory',
            'v3.admin.settings.templates' => 'Admin/SettingsTemplates',
            'v3.admin.cases.index' => 'Admin/CasesIndex',
            'v3.admin.cases.summary' => 'Admin/CaseSummary',
            'v3.admin.subject-runs.index' => 'Admin/RunIndex',
            'v3.admin.operations.index' => 'Admin/OperationsIndex',
            'v3.admin.operations.show' => 'Admin/OperationShow',
            'v3.admin.evidence.index' => 'Admin/EvidenceIndex',
            'v3.admin.devices', 'Admin/Devices' => 'Admin/Devices',

            // Errors
            'v3.errors.403', 'v3.errors.404', 'v3.errors.409', 'v3.errors.503' => 'Errors/Status',

            // Core Educational Resources
            'lectures.show', 'sections.show' => 'LearningContentShow',
            'subjects.show' => 'LearningSubjectShow',
            'subjects.index' => 'Subjects/Index',
            'subjects.create', 'subjects.edit' => 'Subjects/Form',
            'lectures.index' => 'Lectures/Index',
            'lectures.create', 'lectures.edit' => 'Lectures/Form',
            'sections.index' => 'Sections/Index',
            'sections.create', 'sections.edit' => 'Sections/Form',
            'questions.index' => 'Questions/Index',
            'questions.create', 'questions.edit' => 'Questions/Form',
            'questions.show' => 'Questions/Show',
            'exam_questions.index' => 'ExamQuestions/Index',
            'exam_questions.create', 'exam_questions.edit' => 'ExamQuestions/Form',
            'exam_questions.show' => 'ExamQuestions/Show',
            'users.index' => 'Users/Index',
            'users.create', 'users.edit' => 'Users/Form',
            'users.show' => 'Users/Show',
            'teacher_permissions.index' => 'TeacherPermissions/Index',
            'teacher_permissions.create', 'teacher_permissions.edit' => 'TeacherPermissions/Form',
            'teacher_permissions.show' => 'TeacherPermissions/Show',
            'teacher-study-flow.lectures' => 'Teacher/ManualLectures',

            default => 'Errors/Status',
        };
        $response = Inertia::render($component, [...$props, 'screen' => $screen])->toResponse(request());
        $response->setStatusCode($status);
        foreach ($headers as $name => $value) $response->headers->set($name, $value);
        return $response;
    }
}
