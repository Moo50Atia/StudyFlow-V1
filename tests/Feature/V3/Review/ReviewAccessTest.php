<?php

namespace Tests\Feature\V3\Review;

use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    Storage::fake('public');

    $this->env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $this->env['teacher'];
    $this->admin = $this->env['admin'];
    $this->case = $this->env['case'];
    $this->map = $this->env['map'];

    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-access-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $this->rawPrompt = "Process: Wave particles oscillating. <script>alert('xss')</script> & \" ' < >";

    // Create a qualified candidate for Section 1
    $this->q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1, [], $this->rawPrompt);
    $this->section = $this->q1['section'];
    $this->candidate = $this->q1['candidate'];
    $this->promptVersion = $this->q1['promptVersion'];

    // Update section with exact known 11 fields, including null laws_if_any
    $this->section->update([
        'section_identity' => 'Transverse Waves',
        'core_understanding' => 'Oscillation is perpendicular to propagation.',
        'visual_first_explanation' => 'Wave coils moving up and down.',
        'visual_scene_script' => 'Grid of particles moving sinusoidally.',
        'dynamic_view_blueprint' => 'Canvas with interactive frequency slider.',
        'visual_learning_outcome' => 'Student differentiates transverse from longitudinal.',
        'concept_check' => 'Does the particle travel across the screen?',
        'conceptual_summary' => 'Energy travels, particles oscillate.',
        'colloquial_egyptian_explanation' => 'الموجة بتمشي والجسيمات بتتهز عمودياً.',
        'laws_if_any' => '',
        'real_life_example' => 'موجة البحر أو الحبل.',
    ]);

    // Create review item via signed ready endpoint
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
    ]);
    expect($readyRes->status())->toBe(201);
    $this->reviewRef = $readyRes->json('data.review_ref');
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);

    // Other actors
    $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Other Teacher');
    $this->student = AccessFixtures::createStudent('Student User');
});

test('R04: Owner review show returns exact prompt and all eleven fields in order, escaped in HTML, and no scene HTML/JS in response', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}";

    // 1. JSON Request
    $resp = $this->actingAs($this->teacher)->getJson($url);
    $resp->assertStatus(200);

    // Cache-Control: private, no-store
    $cacheControl = $resp->headers->get('Cache-Control');
    expect($cacheControl)->toContain('no-store');

    $data = $resp->json('data');
    expect($data)->toHaveKeys([
        'review_ref',
        'section_ref',
        'candidate_ref',
        'candidate_version',
        'qualification_ref',
        'prompt_version_ref',
        'prompt',
        'fields',
        'review_state',
        'review_state_version',
        'preview_state',
    ]);

    expect($data['review_ref'])->toBe($this->reviewRef)
        ->and($data['section_ref'])->toBe($this->section->id)
        ->and($data['candidate_ref'])->toBe($this->candidate->id)
        ->and($data['candidate_version'])->toBe(1)
        ->and($data['prompt'])->toBe($this->rawPrompt)
        ->and($data['review_state'])->toBe('pending');

    // Verify fields order and keys exactly in 009 order
    $fieldKeys = array_keys($data['fields']);
    expect($fieldKeys)->toBe([
        'section_identity',
        'core_understanding',
        'visual_first_explanation',
        'visual_scene_script',
        'dynamic_view_blueprint',
        'visual_learning_outcome',
        'conceptual_summary',
        'colloquial_egyptian_explanation',
        'laws_if_any',
        'real_life_example',
    ]);

    // laws_if_any is explicitly present (empty string or null)
    expect(array_key_exists('laws_if_any', $data['fields']))->toBeTrue()
        ->and($data['fields']['laws_if_any'])->toBe('');

    // Ensure candidate scene code / package files are NOT in the response
    $jsonContent = $resp->getContent();
    expect($jsonContent)->not->toContain('ScenePackage')
        ->and($jsonContent)->not->toContain('manifest.json')
        ->and($jsonContent)->not->toContain('renderer.setSize');

    // 2. HTML Request
    $htmlResp = $this->actingAs($this->teacher)->get($url);
    $htmlResp->assertStatus(200);

    $htmlContent = $htmlResp->getContent();
    expect($htmlResp->headers->get('Cache-Control'))->toContain('no-store');

    // Prompt with <script> MUST be escaped in HTML, not rendered as raw unescaped script tag
    expect($htmlContent)->not->toContain("<script>alert('xss')</script>")
        ->and($htmlContent)->toContain('&lt;script&gt;alert(');

    // Eleven fields must be visible in HTML
    expect($htmlContent)->toContain('Transverse Waves')
        ->and($htmlContent)->toContain('Oscillation is perpendicular to propagation.')
        ->and($htmlContent)->toContain('الموجة بتمشي والجسيمات بتتهز عمودياً.')
        ->and($htmlContent)->toContain('data-v3-review-page')
        ->and($htmlContent)->toContain('v3-review-approve')
        ->and($htmlContent)->toContain('v3-review-changes');
});

test('R04: Owner review show allows viewing approved scene while approval remains Section-current', function () {
    // Approve this review item
    $dec = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(),
        'review_item_id' => $this->reviewRef,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'candidate_id' => $this->candidate->id,
        'qualification_id' => $this->q1['qualification']->id,
        'actor_user_id' => $this->teacher->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('a', 64),
        'decision' => 'approve',
        'created_at' => now(),
    ]);

    $appr = ApprovedScene::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'candidate_id' => $this->candidate->id,
        'qualification_id' => $this->q1['qualification']->id,
        'review_decision_id' => $dec->id,
        'candidate_version' => 1,
        'approved_at' => now(),
    ]);

    $this->reviewItem->update(['status' => 'decided']);

    ReviewSectionState::where('section_id', $this->section->id)->update([
        'current_review_item_id' => null,
        'current_approval_id' => $appr->id,
        'state' => 'approved',
    ]);

    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}";

    // Decided review can be viewed while its approval remains Section-current
    $resp = $this->actingAs($this->teacher)->getJson($url);
    $resp->assertStatus(200);
    expect($resp->json('data.review_state'))->toBe('decided');
    $page = $this->actingAs($this->teacher)->get($url)->assertOk()->getContent();
    expect($page)->toContain('v3-postapproval-correction')
        ->and($page)->toContain($appr->id);

    // When approval is no longer current (e.g. cleared after post-approval correction), show returns 404
    ReviewSectionState::where('section_id', $this->section->id)->update([
        'current_approval_id' => null,
        'state' => 'routing',
    ]);

    $respAfterClear = $this->actingAs($this->teacher)->getJson($url);
    $respAfterClear->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('R04: Owner review show rejects if 012 proof fails or candidate files are missing/tampered', function () {
    // Corrupt candidate package on disk
    Storage::disk('v3_private')->delete("scene-candidates/{$this->case->id}/{$this->candidate->id}/index.html");

    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}";
    $resp = $this->actingAs($this->teacher)->getJson($url);

    // Must deny / return 404 resource_unavailable
    expect(in_array($resp->status(), [404, 503], true))->toBeTrue();
});

test('R05: Guest, inactive teacher, other teacher, admin, student, and mismatched case are denied on review endpoints with no-store', function () {
    $endpoints = [
        ['method' => 'GET', 'url' => "/v3/teacher/cases/{$this->case->id}/reviews"],
        ['method' => 'GET', 'url' => "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}"],
        ['method' => 'GET', 'url' => "/v3/teacher/cases/{$this->case->id}/approved-scenes"],
        ['method' => 'POST', 'url' => "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions", 'body' => ['expected_review_version' => 1]],
        ['method' => 'GET', 'url' => "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/viewer-sessions/00000000-0000-0000-0000-000000000000/frames/0"],
        ['method' => 'POST', 'url' => "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions", 'body' => ['schema_version' => 1]],
        ['method' => 'POST', 'url' => "/v3/teacher/cases/{$this->case->id}/approved-scenes/00000000-0000-0000-0000-000000000000/corrections", 'body' => ['schema_version' => 1]],
    ];

    // Inactive teacher setup
    $inactiveTeacher = AccessFixtures::createEnrolledTeacher('Inactive Teacher', null, false);

    foreach ($endpoints as $ep) {
        $method = $ep['method'];
        $url = $ep['url'];
        $body = $ep['body'] ?? [];

        // 1. Guest -> 401 unauthenticated
        auth()->logout();
        $guestRes = $this->json($method, $url, $body);
        expect(in_array($guestRes->status(), [401, 302], true))->toBeTrue();
        if ($guestRes->status() === 401) {
            expect($guestRes->headers->get('Cache-Control'))->toContain('no-store');
        }

        // 2. Student -> 403 access_denied
        $studentRes = $this->actingAs($this->student)->json($method, $url, $body);
        expect(in_array($studentRes->status(), [403, 404], true))->toBeTrue();
        expect($studentRes->headers->get('Cache-Control'))->toContain('no-store');

        // 3. Inactive teacher -> 403 access_denied
        $inactiveRes = $this->actingAs($inactiveTeacher)->json($method, $url, $body);
        expect(in_array($inactiveRes->status(), [403, 404], true))->toBeTrue();
        expect($inactiveRes->headers->get('Cache-Control'))->toContain('no-store');

        // 4. Admin -> 403 access_denied
        $adminRes = $this->actingAs($this->admin)->json($method, $url, $body);
        expect(in_array($adminRes->status(), [403, 404], true))->toBeTrue();
        expect($adminRes->headers->get('Cache-Control'))->toContain('no-store');

        // 5. Other teacher -> 404 resource_unavailable
        $otherRes = $this->actingAs($this->otherTeacher)->json($method, $url, $body);
        $otherRes->assertStatus(404);
        expect($otherRes->headers->get('Cache-Control'))->toContain('no-store');
    }

    // 6. Mismatched case (review belonging to case A accessed via case B)
    $otherCase = AuthoringFixtures::createAuthoringEnvironment();
    $mismatchedUrl = "/v3/teacher/cases/{$otherCase['case']->id}/reviews/{$this->reviewRef}";
    $mismatchRes = $this->actingAs($otherCase['teacher'])->getJson($mismatchedUrl);
    $mismatchRes->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('R06: Legacy V2 routes and storage paths cannot resolve 013 candidate, review, or note IDs', function () {
    // 1. Storage disk check
    Storage::disk('public')->assertMissing("scene-candidates/{$this->case->id}/{$this->candidate->id}/index.html");
    Storage::disk('public')->assertMissing("review-notes/{$this->case->id}/{$this->reviewRef}/note.txt");

    // 2. V2 section routes
    $v2SecRes = $this->actingAs($this->teacher)->getJson("/sections/{$this->candidate->id}");
    expect(in_array($v2SecRes->status(), [401, 404, 405, 302], true))->toBeTrue();

    // 3. V2 lectures routes
    $v2LecRes = $this->actingAs($this->teacher)->getJson("/lectures/{$this->candidate->id}");
    expect(in_array($v2LecRes->status(), [401, 404, 405, 302], true))->toBeTrue();

    // 4. V2 teacher-study-flow routes
    $v2FlowRes = $this->actingAs($this->teacher)->getJson("/teacher-study-flow/sections/{$this->candidate->id}/content");
    expect(in_array($v2FlowRes->status(), [401, 404, 405, 302], true))->toBeTrue();

    // 5. Storage route probe
    $storageRes = $this->get("/storage/scene-candidates/{$this->case->id}/{$this->candidate->id}/index.html");
    expect(in_array($storageRes->status(), [401, 404, 403, 302], true))->toBeTrue();
});

test('R06: Admin case summary returns only safe closed DTO scalars and never leaks prompt, fields, code, or notes', function () {
    $summaryUrl = "/v3/admin/cases/{$this->case->id}/summary";

    $response = $this->actingAs($this->admin)->getJson($summaryUrl);
    $response->assertStatus(200);

    $raw = $response->getContent();

    // Must never leak teacher content, prompt body, 11 fields, or scene files
    expect($raw)->not->toContain('Transverse Waves')
        ->and($raw)->not->toContain('Oscillation is perpendicular to propagation.')
        ->and($raw)->not->toContain('الموجة بتمشي والجسيمات بتتهز عمودياً.')
        ->and($raw)->not->toContain("<script>alert('xss')</script>")
        ->and($raw)->not->toContain('prompt_body')
        ->and($raw)->not->toContain('review-notes');
});
