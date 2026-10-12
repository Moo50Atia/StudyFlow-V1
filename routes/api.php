<?php

use App\Http\Controllers\V3\Internal\CaseEventController;
use App\Http\Controllers\V3\Internal\LessonQueryController;
use App\Http\Controllers\V3\Internal\MapContextController;
use App\Http\Controllers\V3\Internal\SourceBindingController;
use App\Http\Controllers\V3\Internal\SourceEffectController;
use App\Http\Controllers\V3\Internal\SourceExtractionController;
use App\Http\Controllers\V3\Internal\SourceInputController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')->middleware('v3.worker.signature')->group(function () {
    Route::get('cases/{case_id}/operation-control', [\App\Http\Controllers\V3\Internal\OperationControlController::class, 'show'])->name('api.v3.internal.cases.operation-control.show');
    Route::post('case-events', [CaseEventController::class, 'store'])->name('api.v3.internal.case-events.store');
    Route::get('source-activations/{activation_id}/pdf', [SourceInputController::class, 'streamPdf'])->name('api.v3.internal.source-activations.pdf');
    Route::get('source-activations/{activation_id}/steps', [SourceInputController::class, 'streamSteps'])->name('api.v3.internal.source-activations.steps');
    Route::get('source-activations/{activation_id}/extraction', [SourceExtractionController::class, 'getExtraction'])->name('api.v3.internal.source-activations.extraction.get');
    Route::post('source-activations/{activation_id}/extraction', [SourceExtractionController::class, 'storeExtraction'])->name('api.v3.internal.source-activations.extraction.store');
    Route::get('source-activations/{activation_id}/extracted-sources/{extracted_source_ref}/text', [SourceExtractionController::class, 'getText'])->name('api.v3.internal.source-activations.extraction.text');
    Route::post('source-effects/{case_id}/{operation}/claim', [SourceEffectController::class, 'claim'])->name('api.v3.internal.source-effects.claim');
    Route::post('source-effects/{effect_id}/result', [SourceEffectController::class, 'result'])->name('api.v3.internal.source-effects.result');
    Route::get('source-activations/{activation_id}/binding', [SourceBindingController::class, 'getByActivation'])->name('api.v3.internal.source-activations.binding');
    Route::get('cases/{case_id}/source-bindings/{binding_ref}', [SourceBindingController::class, 'getByCaseAndRef'])->name('api.v3.internal.cases.source-bindings.get');

    // 009 Lesson routes
    Route::get('map-activations/{activation_id}/context', [MapContextController::class, 'getContext'])->name('api.v3.internal.map-activations.context');
    Route::post('lesson-queries/{case_id}/{kind}/{ordinal}/claim', [LessonQueryController::class, 'claim'])->name('api.v3.internal.lesson-queries.claim');
    Route::post('cases/{case_id}/lesson-queries/{kind}/{ordinal}/claim', [LessonQueryController::class, 'claim'])->name('api.v3.internal.cases.lesson-queries.claim');
    Route::get('cases/{case_id}/lesson-queries/{kind}/{ordinal}', [LessonQueryController::class, 'findDirect'])->name('api.v3.internal.cases.lesson-queries.find-direct');
    Route::get('lesson-queries/{query_ref}', [LessonQueryController::class, 'show'])->name('api.v3.internal.lesson-queries.show');
    Route::post('lesson-queries/{query_ref}/result', [LessonQueryController::class, 'result'])->name('api.v3.internal.lesson-queries.result');
    Route::post('lesson-queries/{query_ref}/uncertain', [LessonQueryController::class, 'uncertain'])->name('api.v3.internal.lesson-queries.uncertain');
    Route::post('lesson-queries/{query_ref}/combined-result', [\App\Http\Controllers\V3\Internal\NotebookProtocolController::class, 'combinedResult'])->name('api.v3.internal.lesson-queries.combined-result');
    Route::get('cases/{case_id}/lesson-context/{work_ordinal}', [MapContextController::class, 'lessonContext'])->name('api.v3.internal.cases.lesson-context');
    Route::post('cases/{case_id}/lesson-completions', [\App\Http\Controllers\V3\Internal\LessonCompletionController::class, 'store'])->name('api.v3.internal.cases.lesson-completions');
    // 010 Prompt routes
    Route::get('cases/{case_id}/prompt-context/{work_ordinal}', [\App\Http\Controllers\V3\Internal\PromptContextController::class, 'getContext'])->name('api.v3.internal.cases.prompt-context');
    Route::post('cases/{case_id}/prompt-work-items/{work_ordinal}/claim', [\App\Http\Controllers\V3\Internal\PromptTurnController::class, 'claimWorkItem'])->name('api.v3.internal.cases.prompt-work-items.claim');
    Route::post('cases/{case_id}/prompt-work-items/{work_item_ref}/complete', \App\Http\Controllers\V3\Internal\PromptCompletionController::class)->name('api.v3.internal.cases.prompt-work-items.complete');
    Route::get('cases/{case_id}/prompt-work-items/{work_ordinal}/prompt-versions', [\App\Http\Controllers\V3\Internal\PromptQualificationController::class, 'workPromptVersions'])->name('api.v3.internal.cases.prompt-work-items.prompt-versions');
    Route::post('cases/{case_id}/prompt-work-items/{work_item_ref}/turns/{step}/claim', [\App\Http\Controllers\V3\Internal\PromptTurnController::class, 'claimTurn'])->name('api.v3.internal.cases.prompt-turns.claim');
    Route::get('cases/{case_id}/prompt-turns/{turn_ref}', [\App\Http\Controllers\V3\Internal\PromptTurnController::class, 'getTurn'])->name('api.v3.internal.cases.prompt-turns.get');
    Route::post('cases/{case_id}/prompt-turns/{turn_ref}/result', [\App\Http\Controllers\V3\Internal\PromptTurnController::class, 'result'])->name('api.v3.internal.cases.prompt-turns.result');
    Route::post('cases/{case_id}/prompt-turns/{turn_ref}/uncertain', [\App\Http\Controllers\V3\Internal\PromptTurnController::class, 'uncertain'])->name('api.v3.internal.cases.prompt-turns.uncertain');
    Route::get('cases/{case_id}/prompt-versions/{prompt_version_ref}/qualification-context', [\App\Http\Controllers\V3\Internal\PromptQualificationController::class, 'qualificationContext'])->name('api.v3.internal.cases.prompt-versions.qualification-context');
    Route::post('cases/{case_id}/prompt-versions/{prompt_version_ref}/jev-attempts/claim', [\App\Http\Controllers\V3\Internal\PromptQualificationController::class, 'claimAttempt'])->name('api.v3.internal.cases.prompt-versions.jev-attempts.claim');
    Route::post('cases/{case_id}/prompt-jev-attempts/{attempt_ref}/result', [\App\Http\Controllers\V3\Internal\PromptQualificationController::class, 'recordResult'])->name('api.v3.internal.cases.prompt-jev-attempts.result');
    Route::post('cases/{case_id}/prompt-jev-attempts/{attempt_ref}/technical-failure', [\App\Http\Controllers\V3\Internal\PromptQualificationController::class, 'recordTechnicalFailure'])->name('api.v3.internal.cases.prompt-jev-attempts.technical-failure');
    Route::get('cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback-context', [\App\Http\Controllers\V3\Internal\PromptFeedbackController::class, 'feedbackContext'])->name('api.v3.internal.cases.prompt-versions.feedback-context');
    Route::post('cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback/claim', [\App\Http\Controllers\V3\Internal\PromptFeedbackController::class, 'claimFeedback'])->name('api.v3.internal.cases.prompt-versions.feedback.claim');
    Route::post('cases/{case_id}/prompt-feedback-effects/{effect_ref}/result', [\App\Http\Controllers\V3\Internal\PromptFeedbackController::class, 'result'])->name('api.v3.internal.cases.prompt-feedback-effects.result');
    Route::post('cases/{case_id}/prompt-feedback-effects/{effect_ref}/uncertain', [\App\Http\Controllers\V3\Internal\PromptFeedbackController::class, 'uncertain'])->name('api.v3.internal.cases.prompt-feedback-effects.uncertain');
    Route::get('cases/{case_id}/qualified-prompts/{prompt_version_ref}', [\App\Http\Controllers\V3\Internal\QualifiedPromptController::class, 'show'])->name('api.v3.internal.cases.qualified-prompts.show');

    // 011 Scene Authoring routes
    Route::get('cases/{case_id}/scene-authoring/eligible-prompts', [\App\Http\Controllers\V3\Internal\AuthoringRequestController::class, 'eligiblePrompts'])->name('api.v3.internal.cases.scene-authoring.eligible-prompts');
    Route::post('cases/{case_id}/scene-authoring/requests', [\App\Http\Controllers\V3\Internal\AuthoringRequestController::class, 'store'])->name('api.v3.internal.cases.scene-authoring.requests.store');
    Route::get('cases/{case_id}/scene-authoring/requests/{request_ref}/context', [\App\Http\Controllers\V3\Internal\AuthoringRequestController::class, 'context'])->name('api.v3.internal.cases.scene-authoring.requests.context');
    Route::post('cases/{case_id}/scene-authoring/requests/{request_ref}/attempts', [\App\Http\Controllers\V3\Internal\CodingAttemptController::class, 'reserve'])->name('api.v3.internal.cases.scene-authoring.requests.attempts.reserve');
    Route::get('cases/{case_id}/scene-authoring/attempts/{attempt_ref}', [\App\Http\Controllers\V3\Internal\CodingAttemptController::class, 'show'])->name('api.v3.internal.cases.scene-authoring.attempts.show');
    Route::post('cases/{case_id}/scene-authoring/attempts/{attempt_ref}/result', [\App\Http\Controllers\V3\Internal\CodingAttemptController::class, 'recordResult'])->name('api.v3.internal.cases.scene-authoring.attempts.result');
    Route::get('cases/{case_id}/scene-candidates/{candidate_ref}', [\App\Http\Controllers\V3\Internal\SceneCandidateController::class, 'show'])->name('api.v3.internal.cases.scene-candidates.show');

    // 012 Scene Qualification routes
    Route::post('cases/{case_id}/scene-check-attempts', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'claim'])->name('api.v3.internal.cases.scene-check-attempts.claim');
    Route::get('cases/{case_id}/scene-check-attempts/{attempt_ref}', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'show'])->name('api.v3.internal.cases.scene-check-attempts.show');
    Route::post('cases/{case_id}/scene-check-attempts/{attempt_ref}/result', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'result'])->name('api.v3.internal.cases.scene-check-attempts.result');
    Route::post('cases/{case_id}/scene-check-attempts/{attempt_ref}/technical-failure', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'technicalFailure'])->name('api.v3.internal.cases.scene-check-attempts.technical-failure');
    Route::post('cases/{case_id}/scene-jev-attempts', [\App\Http\Controllers\V3\Internal\SceneJevController::class, 'claim'])->name('api.v3.internal.cases.scene-jev-attempts.claim');
    Route::get('cases/{case_id}/scene-jev-attempts/{attempt_ref}', [\App\Http\Controllers\V3\Internal\SceneJevController::class, 'show'])->name('api.v3.internal.cases.scene-jev-attempts.show');
    Route::get('cases/{case_id}/scene-jev-attempts/{attempt_ref}/context', [\App\Http\Controllers\V3\Internal\SceneJevController::class, 'context'])->name('api.v3.internal.cases.scene-jev-attempts.context');
    Route::post('cases/{case_id}/scene-jev-attempts/{attempt_ref}/result', [\App\Http\Controllers\V3\Internal\SceneJevController::class, 'result'])->name('api.v3.internal.cases.scene-jev-attempts.result');
    Route::post('cases/{case_id}/scene-jev-attempts/{attempt_ref}/technical-failure', [\App\Http\Controllers\V3\Internal\SceneJevController::class, 'technicalFailure'])->name('api.v3.internal.cases.scene-jev-attempts.technical-failure');
    Route::post('cases/{case_id}/scene-repairs', [\App\Http\Controllers\V3\Internal\SceneRepairController::class, 'claim'])->name('api.v3.internal.cases.scene-repairs.claim');
    Route::get('cases/{case_id}/scene-repairs/{repair_ref}/context', [\App\Http\Controllers\V3\Internal\SceneRepairController::class, 'context'])->name('api.v3.internal.cases.scene-repairs.context');
    Route::post('cases/{case_id}/scene-repairs/{repair_ref}/candidate', [\App\Http\Controllers\V3\Internal\SceneRepairController::class, 'linkCandidate'])->name('api.v3.internal.cases.scene-repairs.candidate');
    Route::get('cases/{case_id}/scene-qualification/review-eligible/{candidate_ref}', [\App\Http\Controllers\V3\Internal\SceneEligibilityController::class, 'show'])->name('api.v3.internal.cases.scene-qualification.review-eligible');

    // 013 Teacher Review routes
    Route::post('cases/{case_id}/reviews/ready', [\App\Http\Controllers\V3\Internal\ReviewReadyController::class, 'ready'])->name('api.v3.internal.cases.reviews.ready');
    Route::get('cases/{case_id}/reviews/current', [\App\Http\Controllers\V3\Internal\ReviewReadyController::class, 'current'])->name('api.v3.internal.cases.reviews.current');
    Route::post('cases/{case_id}/teacher-scene-repairs', [\App\Http\Controllers\V3\Internal\TeacherSceneRepairController::class, 'claim'])->name('api.v3.internal.cases.teacher-scene-repairs.claim');
    Route::post('cases/{case_id}/teacher-prompt-corrections/{route_ref}/claim', [\App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController::class, 'claim'])->name('api.v3.internal.cases.teacher-prompt-corrections.claim');
    Route::get('cases/{case_id}/teacher-prompt-corrections/{effect_ref}/context', [\App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController::class, 'context'])->name('api.v3.internal.cases.teacher-prompt-corrections.context');
    Route::post('cases/{case_id}/teacher-prompt-corrections/{effect_ref}/result', [\App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController::class, 'result'])->name('api.v3.internal.cases.teacher-prompt-corrections.result');
    Route::post('cases/{case_id}/teacher-prompt-corrections/{effect_ref}/uncertain', [\App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController::class, 'uncertain'])->name('api.v3.internal.cases.teacher-prompt-corrections.uncertain');

    // 014 Admin Operation routes
    Route::post('admin-control-results', [\App\Http\Controllers\V3\Internal\AdminControlResultController::class, 'recordResult'])->name('api.v3.internal.admin-control-results.store');
});

Route::prefix('v3/internal')->middleware('v3.worker.signature')->group(function () {
    Route::get('cases/{case_id}/scene-candidates/{candidate_ref}', [\App\Http\Controllers\V3\Internal\SceneCandidateController::class, 'show'])->name('api.v3.internal.cases.scene-candidates.show-v3');
    Route::post('cases/{case_id}/scene-check-attempts', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'claim'])->name('api.v3.internal.cases.scene-check-attempts.claim-v3');
    Route::get('cases/{case_id}/scene-check-attempts/{attempt_ref}', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'show'])->name('api.v3.internal.cases.scene-check-attempts.show-v3');
    Route::post('cases/{case_id}/scene-check-attempts/{attempt_ref}/result', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'result'])->name('api.v3.internal.cases.scene-check-attempts.result-v3');
    Route::post('cases/{case_id}/scene-check-attempts/{attempt_ref}/technical-failure', [\App\Http\Controllers\V3\Internal\SceneCheckController::class, 'technicalFailure'])->name('api.v3.internal.cases.scene-check-attempts.technical-failure-v3');
});

// Spec 019: Web Pipeline internal routes
Route::prefix('v3/internal/web-pipeline')->middleware('v3.worker.signature')->group(function () {
    Route::post('items/{item}/prepare', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'prepareRunItem'])->name('api.v3.internal.web-pipeline.items.prepare');
    Route::post('runs/{run}/notebook', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'recordRunNotebook'])->name('api.v3.internal.web-pipeline.runs.notebook');
    Route::post('items/{item}/source', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'recordRunItemSource'])->name('api.v3.internal.web-pipeline.items.source');
    Route::post('items/{item}/archive', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'recordRunItemArchive'])->name('api.v3.internal.web-pipeline.items.archive');
    Route::post('items/{item}/reset-verified', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'markRunItemResetVerified'])->name('api.v3.internal.web-pipeline.items.reset-verified');
    Route::post('runs/{run}/export', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'exportRunPrompts'])->name('api.v3.internal.web-pipeline.runs.export');
    Route::get('items/{item}/input', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'acceptedInput'])->name('api.v3.internal.web-pipeline.items.input');
    Route::get('frameworks/{version}/body', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'frameworkBody'])->name('api.v3.internal.web-pipeline.frameworks.body');
    Route::post('claims', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'claim'])->name('api.v3.internal.web-pipeline.claims');
    Route::get('contexts/{ref}', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'getContext'])->name('api.v3.internal.web-pipeline.contexts');
    Route::get('manifests/{ref}', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'getManifest'])->name('api.v3.internal.web-pipeline.manifests');
    Route::post('results', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'result'])->name('api.v3.internal.web-pipeline.results');
    Route::get('commands/{command}/receipt', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'commandReceipt'])->name('api.v3.internal.web-pipeline.commands.receipt');
    Route::post('events', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'recordEvent'])->name('api.v3.internal.web-pipeline.events');
    Route::get('artifacts/{ref}', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'getArtifact'])->name('api.v3.internal.web-pipeline.artifacts.get');
    Route::put('operations/{operation}/artifacts/{ref}', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'putArtifact'])->name('api.v3.internal.web-pipeline.artifacts.put');
    Route::post('imports/verify', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'verifyImport'])->name('api.v3.internal.web-pipeline.imports.verify');
    Route::post('imports/materialize', [\App\Http\Controllers\V3\Internal\WebPipelineController::class, 'materializeImport'])->name('api.v3.internal.web-pipeline.imports.materialize');
});
