<?php

use App\Http\Controllers\V3\Admin\AccountController;
use App\Http\Controllers\V3\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\V3\EntryController;
use App\Http\Controllers\V3\Teacher\WorkspaceController as TeacherWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['v3.private', 'web'])->prefix('v3')->group(function () {
    // V3 Entry - any active V3 actor
    Route::get('/', [EntryController::class, 'index'])
        ->middleware('v3.access')
        ->name('v3.entry');

    // Teacher routes
    Route::prefix('teacher')->middleware('v3.role:teacher')->group(function () {
        Route::get('/', [TeacherWorkspaceController::class, 'index'])
            ->name('v3.teacher.workspace');

        Route::post('/pdf-uploads', [\App\Http\Controllers\V3\Teacher\PdfUploadController::class, 'store'])
            ->name('v3.teacher.pdf-uploads.store');

        Route::get('/pdf-uploads', [\App\Http\Controllers\V3\Teacher\PdfUploadController::class, 'index'])
            ->name('v3.teacher.pdf-uploads.index');

        Route::get('/pdf-uploads/{attempt}', [\App\Http\Controllers\V3\Teacher\PdfUploadController::class, 'show'])
            ->name('v3.teacher.pdf-uploads.show');

        Route::post('/cases', [\App\Http\Controllers\V3\Teacher\ProcessingCaseController::class, 'store'])
            ->name('v3.teacher.cases.store');

        Route::get('/cases/{case}', [\App\Http\Controllers\V3\Teacher\ProcessingCaseController::class, 'show'])
            ->name('v3.teacher.cases.show');

        Route::get('/documents/{document}/download', [\App\Http\Controllers\V3\Teacher\DocumentController::class, 'download'])
            ->name('v3.teacher.documents.download');

        Route::get('/cases/{case}/progress', [\App\Http\Controllers\V3\Teacher\CaseProgressController::class, 'show'])
            ->name('v3.teacher.cases.progress');

        Route::get('/cases/{case}/source-status', [\App\Http\Controllers\V3\Teacher\CaseSourceStatusController::class, 'show'])
            ->name('v3.teacher.cases.source-status');

        Route::get('/cases/{case}/lesson-map', [\App\Http\Controllers\V3\Teacher\LessonMapController::class, 'show'])
            ->name('v3.teacher.cases.lesson-map');

        Route::get('/cases/{case}/sections/{section}', [\App\Http\Controllers\V3\Teacher\TeacherSectionController::class, 'show'])
            ->name('v3.teacher.cases.sections.show');

        Route::get('/cases/{case}/prompt-status', [\App\Http\Controllers\V3\Teacher\PromptStatusController::class, 'show'])
            ->name('v3.teacher.cases.prompt-status');

        Route::get('/cases/{case}/scene-authoring', [\App\Http\Controllers\V3\Teacher\SceneAuthoringStatusController::class, 'show'])
            ->name('v3.teacher.cases.scene-authoring');

        Route::get('/cases/{case}/scene-qualification', [\App\Http\Controllers\V3\Teacher\SceneQualificationStatusController::class, 'show'])
            ->name('v3.teacher.cases.scene-qualification');

        // V3 Review routes
        Route::get('/cases/{case}/reviews', [\App\Http\Controllers\V3\Teacher\SceneReviewController::class, 'index'])
            ->name('v3.teacher.cases.reviews.index');

        Route::get('/cases/{case}/reviews/{review}', [\App\Http\Controllers\V3\Teacher\SceneReviewController::class, 'show'])
            ->name('v3.teacher.cases.reviews.show');

        Route::get('/cases/{case}/approved-scenes', [\App\Http\Controllers\V3\Teacher\SceneReviewController::class, 'approvedScenes'])
            ->name('v3.teacher.cases.approvals.index');

        Route::post('/cases/{case}/reviews/{review}/viewer-sessions', [\App\Http\Controllers\V3\Teacher\ReviewPreviewController::class, 'start'])
            ->name('v3.teacher.cases.reviews.viewer.start');

        Route::get('/cases/{case}/reviews/{review}/viewer-sessions/{viewer}', [\App\Http\Controllers\V3\Teacher\ReviewPreviewController::class, 'show'])
            ->name('v3.teacher.cases.reviews.viewer.show');

        Route::get('/cases/{case}/reviews/{review}/viewer-sessions/{viewer}/frames/{frame_no}', [\App\Http\Controllers\V3\Teacher\ReviewPreviewController::class, 'frame'])
            ->name('v3.teacher.cases.reviews.viewer.frame');

        Route::post('/cases/{case}/reviews/{review}/viewer-sessions/{viewer}/actions', [\App\Http\Controllers\V3\Teacher\ReviewPreviewController::class, 'action'])
            ->name('v3.teacher.cases.reviews.viewer.action');

        Route::delete('/cases/{case}/reviews/{review}/viewer-sessions/{viewer}', [\App\Http\Controllers\V3\Teacher\ReviewPreviewController::class, 'close'])
            ->name('v3.teacher.cases.reviews.viewer.close');

        Route::post('/cases/{case}/reviews/{review}/decisions', [\App\Http\Controllers\V3\Teacher\SceneReviewController::class, 'decide'])
            ->name('v3.teacher.cases.reviews.decide');

        Route::post('/cases/{case}/approved-scenes/{approval}/corrections', [\App\Http\Controllers\V3\Teacher\PostapprovalCorrectionController::class, 'correct'])
            ->name('v3.teacher.cases.approvals.correct');

        // V3 Evidence routes
        Route::get('/cases/{case}/evidence', [\App\Http\Controllers\V3\Teacher\EvidenceController::class, 'show'])
            ->name('v3.teacher.evidence.show');

        Route::get('/cases/{case}/evidence/approvals/{approval}', [\App\Http\Controllers\V3\Teacher\EvidenceController::class, 'approval'])
            ->name('v3.teacher.evidence.approvals.show');

        Route::post('/cases/{case}/evidence-exports', [\App\Http\Controllers\V3\Teacher\EvidenceController::class, 'export'])
            ->name('v3.teacher.evidence-exports.store');

        Route::get('/cases/{case}/evidence-exports/{export}/download', [\App\Http\Controllers\V3\Teacher\EvidenceController::class, 'download'])
            ->where('export', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->name('v3.teacher.evidence-exports.download');
    });

    // Admin workspace and accounts
    Route::prefix('admin')->middleware('v3.role:admin')->group(function () {
        Route::get('/', [AdminWorkspaceController::class, 'index'])
            ->name('v3.admin.workspace');

        Route::get('/cases', [\App\Http\Controllers\V3\Admin\CaseSummaryController::class, 'index'])
            ->name('v3.admin.cases.index');

        Route::get('/cases/{case}/summary', [\App\Http\Controllers\V3\Admin\CaseSummaryController::class, 'show'])
            ->name('v3.admin.cases.summary');

        Route::get('/accounts', [AccountController::class, 'index'])
            ->name('v3.admin.accounts.index');

        Route::patch('/accounts/{user}/access', [AccountController::class, 'updateAccess'])
            ->name('v3.admin.accounts.access.update');

        // V3 Settings routes
        Route::get('/settings', [\App\Http\Controllers\V3\Admin\SettingsController::class, 'show'])
            ->name('v3.admin.settings.show');

        Route::get('/settings/templates', [\App\Http\Controllers\V3\Admin\SettingsController::class, 'templates'])
            ->name('v3.admin.settings.templates');

        Route::post('/settings', [\App\Http\Controllers\V3\Admin\SettingsController::class, 'store'])
            ->name('v3.admin.settings.store');

        Route::get('/settings/history', [\App\Http\Controllers\V3\Admin\SettingsController::class, 'history'])
            ->name('v3.admin.settings.history');

        // V3 Operations routes
        Route::get('/operations', [\App\Http\Controllers\V3\Admin\OperationsController::class, 'index'])
            ->name('v3.admin.operations.index');

        Route::get('/operations/health', [\App\Http\Controllers\V3\Admin\OperationsController::class, 'health'])
            ->name('v3.admin.operations.health');

        Route::get('/operations/{case}', [\App\Http\Controllers\V3\Admin\OperationsController::class, 'show'])
            ->where('case', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->name('v3.admin.operations.show');

        Route::post('/operations/{case}/commands', [\App\Http\Controllers\V3\Admin\AdminCommandController::class, 'store'])
            ->where('case', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->name('v3.admin.operations.commands.store');

        Route::post('/operations/{case}/reviews/{review}/reminders', [\App\Http\Controllers\V3\Admin\ReviewReminderController::class, 'store'])
            ->where('case', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->where('review', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->name('v3.admin.operations.reminders.store');

        Route::post('/operations/{case}/deletion-assessments', [\App\Http\Controllers\V3\Admin\DeletionAssessmentController::class, 'store'])
            ->where('case', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->name('v3.admin.operations.deletion-assessments.store');

        // V3 Evidence routes
        Route::get('/evidence/metrics', [\App\Http\Controllers\V3\Admin\EvidenceController::class, 'metrics'])
            ->name('v3.admin.evidence.metrics');

        Route::post('/evidence-exports', [\App\Http\Controllers\V3\Admin\EvidenceController::class, 'export'])
            ->name('v3.admin.evidence-exports.store');

        Route::get('/evidence-exports/{export}/download', [\App\Http\Controllers\V3\Admin\EvidenceController::class, 'download'])
            ->where('export', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
            ->name('v3.admin.evidence-exports.download');
    });
});


