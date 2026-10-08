<?php

namespace Tests\Feature\V3\Workflow;

use Illuminate\Support\Facades\Route;

test('W14 & W17: route inventory contains no premature review, approval, or content pipeline routes', function () {
    $routes = Route::getRoutes()->getRoutes();

    $forbiddenPatterns = [
        '#^v3/teacher/cases/[^/]+/reject#',
        '#^v3/teacher/cases/[^/]+/scenes#',
        '#^v3/teacher/cases/[^/]+/prompts#',
        '#^v3/admin/cases/[^/]+/review#',
        '#^api/internal/v1/review#',
        '#^api/internal/v1/extract#',
        '#^api/internal/v1/scenes#',
        '#^api/internal/v1/prompts#',
    ];

    if (!class_exists(\App\Http\Controllers\V3\Teacher\SceneReviewController::class)) {
        $forbiddenPatterns[] = '#^v3/teacher/cases/[^/]+/review#';
        $forbiddenPatterns[] = '#^v3/teacher/cases/[^/]+/approve#';
    }

    foreach ($routes as $route) {
        $uri = $route->uri();
        foreach ($forbiddenPatterns as $pattern) {
            expect(preg_match($pattern, $uri))->toBe(0, "Forbidden route found in inventory: {$uri}");
        }
    }
});

test('W17: production workflow engine contains no synthetic extraction, prompt, or scene generation activities', function () {
    $engineDir = base_path('v3_engine');
    $activitiesDir = $engineDir . '/activities';

    $files = scandir($activitiesDir);
    $pyFiles = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.py') && $f !== '__init__.py'));

    // Activities for 007, 008, and 009; 010+ activities remain forbidden
    expect($pyFiles)->toEqualCanonicalizing([
        'publish_milestone.py',
        'extract_case_source.py',
        'ingest_case_source.py',
        'map_case.py',
        'prepare_lesson.py',
    ]);

    // Workflow code must truthfully park at handoff
    $workflowCode = file_get_contents($engineDir . '/workflows/case_workflow.py');
    expect($workflowCode)->toContain('await workflow.wait_condition(');
});

test('W18: no premature review or scene tables exist in database migrations', function () {
    $migrationsDir = database_path('migrations');
    $files = scandir($migrationsDir);

    foreach ($files as $file) {
        if (str_ends_with($file, '.php') && str_contains($file, '000007_create_v3_workflow_tables')) {
            $content = file_get_contents($migrationsDir . '/' . $file);
            expect($content)->not->toContain('v3_review_signals')
                ->and($content)->not->toContain('v3_scene_candidates')
                ->and($content)->not->toContain('v3_scenes');
        }
    }
});

test('W10 & W18: log hygiene does not record HMAC secrets or raw payloads', function () {
    $reconcileCode = file_get_contents(app_path('Services/V3/Workflow/ReconcileWorkflowStart.php'));
    expect($reconcileCode)->not->toContain('config(\'services.v3.bridge_hmac_key\')')
        ->and($reconcileCode)->not->toContain('config(\'services.v3.callback_hmac_key\')');

    $middlewareCode = file_get_contents(app_path('Http/Middleware/V3/VerifyWorkerSignature.php'));
    expect($middlewareCode)->not->toContain('Log::warning($secret')
        ->and($middlewareCode)->not->toContain('Log::info($secret');
});
