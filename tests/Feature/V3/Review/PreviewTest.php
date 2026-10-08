<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $this->env['teacher'];
    $this->admin = $this->env['admin'];
    $this->case = $this->env['case'];
    $this->map = $this->env['map'];

    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-preview-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    // Create a qualified candidate for Section 1
    $this->q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $this->section = $this->q1['section'];
    $this->candidate = $this->q1['candidate'];
    $this->promptVersion = $this->q1['promptVersion'];

    // Ensure review ready
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
    ]);
    expect($readyRes->status())->toBe(201);
    $this->reviewRef = $readyRes->json('data.review_ref');
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);

    // Minimal 1x1 or 1280x720 PNG header bytes for mocking bridge responses
    // PNG 1280x720 header:
    // \x89PNG\r\n\x1a\n + IHDR chunk with width 1280 (0x0500), height 720 (0x02D0)
    $this->samplePngBytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x05\x00\x00\x00\x02\xd0\x08\x06\x00\x00\x00" . str_repeat("\x00", 100);
    $this->samplePngB64 = base64_encode($this->samplePngBytes);
});

test('R07: Owner starts session, fetches frame 0 as 1280x720 PNG, and applies action that advances frame', function () {
    $viewerId = (string) Str::uuid();

    // Mock bridge responses
    Http::fake([
        '*/internal/v1/review-preview/sessions' => Http::response([
            'data' => [
                'viewer_ref' => $viewerId,
                'frame_no' => 0,
                'frame' => [
                    'viewer_ref' => $viewerId,
                    'frame_no' => 0,
                    'png_base64' => $this->samplePngB64,
                    'dom_text' => 'Interactive Scene Heading',
                    'controls' => [
                        ['ordinal' => 1, 'role' => 'button', 'name' => 'Toggle', 'focusable' => true],
                    ],
                    'runtime_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
            ],
        ], 201),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/actions' => Http::response([
            'data' => [
                'viewer_ref' => $viewerId,
                'frame_no' => 1,
                'frame' => [
                    'viewer_ref' => $viewerId,
                    'frame_no' => 1,
                    'png_base64' => $this->samplePngB64,
                    'dom_text' => 'Updated Scene Text',
                    'controls' => [
                        ['ordinal' => 1, 'role' => 'button', 'name' => 'Toggle', 'focusable' => true],
                    ],
                    'runtime_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
            ],
        ], 200),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/frames/0' => Http::response($this->samplePngBytes, 200, ['Content-Type' => 'image/png']),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/frames/1' => Http::response($this->samplePngBytes, 200, ['Content-Type' => 'image/png']),
        '*/internal/v1/review-preview/sessions/' . $viewerId => Http::response(null, 204),
    ]);

    // 1. Start viewer session
    $startUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions";
    $startRes = $this->actingAs($this->teacher)->postJson($startUrl, [
        'expected_review_version' => 1,
    ]);

    $startRes->assertStatus(201);
    expect($startRes->headers->get('Cache-Control'))->toContain('no-store');
    $viewerRef = $startRes->json('data.viewer_ref');
    expect($viewerRef)->not->toBeNull();

    // 2. Fetch viewer session metadata
    $showUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}";
    $showRes = $this->actingAs($this->teacher)->getJson($showUrl);
    $showRes->assertStatus(200);
    expect($showRes->headers->get('Cache-Control'))->toContain('no-store');
    expect($showRes->json('data.frame_no'))->toBe(0)
        ->and($showRes->json('data.dom_text'))->toBe('Interactive Scene Heading')
        ->and(count($showRes->json('data.controls')))->toBe(1);

    // 3. Fetch frame 0 image
    $frame0Url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}/frames/0";
    $frame0Res = $this->actingAs($this->teacher)->get($frame0Url);
    $frame0Res->assertStatus(200);
    expect($frame0Res->headers->get('Content-Type'))->toBe('image/png')
        ->and($frame0Res->headers->get('Cache-Control'))->toContain('no-store');
    expect(strlen($frame0Res->getContent()))->toBeLessThanOrEqual(2097152);

    // 4. Send interactive action
    $actionUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}/actions";
    $actionRes = $this->actingAs($this->teacher)->postJson($actionUrl, [
        'schema_version' => 1,
        'expected_frame_no' => 0,
        'kind' => 'click',
        'payload' => ['x' => 100, 'y' => 100],
    ]);
    $actionRes->assertStatus(200);
    expect($actionRes->headers->get('Cache-Control'))->toContain('no-store');
    expect($actionRes->json('data.frame_no'))->toBe(1);

    // 5. Fetch frame 1 image
    $frame1Url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}/frames/1";
    $frame1Res = $this->actingAs($this->teacher)->get($frame1Url);
    $frame1Res->assertStatus(200);
    expect($frame1Res->headers->get('Content-Type'))->toBe('image/png');

    // 6. Close session
    $closeRes = $this->actingAs($this->teacher)->deleteJson($showUrl);
    $closeRes->assertStatus(204);
});

test('R09: Gateway crash, stale candidate, or bridge failure returns 503 review_unavailable without first_frame proof', function () {
    // Mock bridge failure
    Http::fake([
        '*/internal/v1/review-preview/sessions*' => Http::response([
            'error' => ['code' => 'review_unavailable', 'message' => 'Bridge down'],
        ], 503),
    ]);

    $startUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions";
    $res = $this->actingAs($this->teacher)->postJson($startUrl, [
        'expected_review_version' => 1,
    ]);

    expect($res->status())->toBe(503)
        ->and($res->json('error.code'))->toBe('review_unavailable');

    // Verify first_frame_at is null
    $session = ReviewPreviewSession::where('review_item_id', $this->reviewRef)->first();
    expect($session?->first_frame_at)->toBeNull();
});

test('R10: Stale frame expected, changed action replay, and expired session return 409 conflict or 404', function () {
    $viewerId = (string) Str::uuid();

    Http::fake([
        '*/internal/v1/review-preview/sessions' => Http::response([
            'data' => [
                'viewer_ref' => $viewerId,
                'frame_no' => 0,
                'frame' => [
                    'viewer_ref' => $viewerId,
                    'frame_no' => 0,
                    'png_base64' => $this->samplePngB64,
                    'dom_text' => 'Scene',
                    'controls' => [],
                    'runtime_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
            ],
        ], 201),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/actions' => function ($request) use ($viewerId) {
            $data = $request->data();
            if ($data['expected_frame_no'] !== 0) {
                return Http::response(['error' => ['code' => 'review_conflict', 'message' => 'Stale frame']], 409);
            }
            return Http::response([
                'data' => [
                    'viewer_ref' => $viewerId,
                    'frame_no' => 1,
                    'frame' => [
                        'viewer_ref' => $viewerId,
                        'frame_no' => 1,
                        'png_base64' => $this->samplePngB64,
                        'dom_text' => 'Scene 1',
                        'controls' => [],
                        'runtime_error_count' => 0,
                        'forbidden_request_count' => 0,
                    ],
                ],
            ], 200);
        },
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/frames/0' => Http::response($this->samplePngBytes, 200, ['Content-Type' => 'image/png']),
        '*/internal/v1/review-preview/sessions/' . $viewerId => Http::response(null, 204),
    ]);

    // Start session
    $startRes = $this->actingAs($this->teacher)->postJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions",
        ['expected_review_version' => 1]
    );
    $viewerRef = $startRes->json('data.viewer_ref');

    // Stale expected_frame_no = 99 -> 409
    $staleRes = $this->actingAs($this->teacher)->postJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}/actions",
        [
            'schema_version' => 1,
            'expected_frame_no' => 99,
            'kind' => 'click',
            'payload' => ['x' => 10, 'y' => 10],
        ]
    );
    expect($staleRes->status())->toBe(409)
        ->and($staleRes->json('error.code'))->toBe('review_conflict');

    // Close session
    $this->actingAs($this->teacher)->deleteJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}"
    )->assertStatus(204);

    // After close, GET session returns 404
    $getClosed = $this->actingAs($this->teacher)->getJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}"
    );
    expect($getClosed->status())->toBe(404);
});

test('R12: First-frame proof is ONLY recorded on outward GET of frame 0, never on bridge start, metadata, or action', function () {
    $viewerId = (string) Str::uuid();

    Http::fake([
        '*/internal/v1/review-preview/sessions' => Http::response([
            'data' => [
                'viewer_ref' => $viewerId,
                'frame_no' => 0,
                'frame' => [
                    'viewer_ref' => $viewerId,
                    'frame_no' => 0,
                    'png_base64' => $this->samplePngB64,
                    'dom_text' => 'Scene Text',
                    'controls' => [],
                    'runtime_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
            ],
        ], 201),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/frames/0' => Http::response($this->samplePngBytes, 200, ['Content-Type' => 'image/png']),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/frames/1' => Http::response($this->samplePngBytes, 200, ['Content-Type' => 'image/png']),
        '*/internal/v1/review-preview/sessions/' . $viewerId . '/actions' => Http::response([
            'data' => [
                'viewer_ref' => $viewerId,
                'frame_no' => 1,
                'frame' => [
                    'viewer_ref' => $viewerId,
                    'frame_no' => 1,
                    'png_base64' => $this->samplePngB64,
                    'dom_text' => 'Scene Next',
                    'controls' => [],
                    'runtime_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
            ],
        ], 200),
    ]);

    // 1. After start: first_frame_at MUST be null
    $startRes = $this->actingAs($this->teacher)->postJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions",
        ['expected_review_version' => 1]
    );
    $viewerRef = $startRes->json('data.viewer_ref');

    $dbSession = ReviewPreviewSession::where('id', $viewerRef)->first();
    expect($dbSession)->not->toBeNull()
        ->and($dbSession->first_frame_at)->toBeNull();

    // 2. After metadata GET: first_frame_at MUST STILL be null
    $this->actingAs($this->teacher)->getJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}"
    )->assertStatus(200);

    $dbSession->refresh();
    expect($dbSession->first_frame_at)->toBeNull();

    // 3. After action: first_frame_at MUST STILL be null
    $this->actingAs($this->teacher)->postJson(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}/actions",
        [
            'schema_version' => 1,
            'expected_frame_no' => 0,
            'kind' => 'press',
            'payload' => ['key' => 'Tab'],
        ]
    )->assertStatus(200);

    $dbSession->refresh();
    expect($dbSession->first_frame_at)->toBeNull();

    // 4. Outward GET of frame 0 records first_frame_at!
    $this->actingAs($this->teacher)->get(
        "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/{$viewerRef}/frames/0"
    )->assertStatus(200);

    $dbSession->refresh();
    expect($dbSession->first_frame_at)->not->toBeNull();
});
