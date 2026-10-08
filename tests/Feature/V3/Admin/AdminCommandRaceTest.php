<?php

namespace Tests\Feature\V3\Admin;

use App\Models\V3\CaseOperationControl;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Admin\CaseOperationGuard;
use App\Services\V3\Review\SubmitReviewDecision;
use App\Services\V3\Workflow\ProjectCaseEvent;
use App\Services\V3\Workflow\StartWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->admin = AccessFixtures::createEnrolledAdmin('Active Admin');
    $this->teacher = AccessFixtures::createEnrolledTeacher('Active Teacher');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
    $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $canonical,
        'checksum' => $checksum,
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'race_doc_content'),
        'byte_size' => 1024,
        'page_count' => 5,
        'storage_path' => 'documents/race.pdf',
        'state' => 'ready',
        'accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;

function setupCaseWithReview(object $context): array
{
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $context->teacher = $env['teacher'];
    $context->admin = $env['admin'];
    $case = $env['case'];
    $case->state = 'awaiting_review';
    $case->state_version = 2;
    $case->save();

    $link = WorkflowLink::forceCreate([
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'run_id' => 'run-race-test',
        'settings_version_id' => $env['settingsVersion']->id,
        'stage_code' => 'teacher_review',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $q1 = ReviewFixtures::createQualifiedCandidate($env, 1);
    $section = $q1['section'];
    $candidate = $q1['candidate'];

    // Create review item via ready endpoint
    $readyPath = "/api/internal/v1/cases/{$case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($context, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidate->id,
    ]);
    $reviewRef = $readyRes->json('data.review_ref');
    $reviewItem = TeacherReviewItem::find($reviewRef);

    // Frame 0 preview
    ReviewPreviewSession::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'review_item_id' => $reviewItem->id,
        'candidate_id' => $candidate->id,
        'actor_user_id' => $context->teacher->id,
        'first_frame_at' => now(),
        'state' => 'ready',
        'expires_at' => now()->addHour(),
        'idle_expires_at' => now()->addMinutes(15),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'case' => $case,
        'link' => $link,
        'section' => $section,
        'candidate' => $candidate,
        'reviewItem' => $reviewItem,
    ];
}

test('O08: hold fence denies teacher review decision with 409 review_conflict', function () {
    $setup = setupCaseWithReview($this);
    $case = $setup['case'];
    $reviewItem = $setup['reviewItem'];
    $candidate = $setup['candidate'];

    // Put case on hold
    CaseOperationControl::create([
        'case_id' => $case->id,
        'mode' => 'held',
        'control_version' => 1,
        'applied_at' => now(),
    ]);

    $decisionInput = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'candidate_ref' => $candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
    ];

    $submitService = app(SubmitReviewDecision::class);
    $result = $submitService->submit($case, $reviewItem, $decisionInput, $this->teacher);

    expect($result['status'])->toBe(409)
        ->and($result['error']['code'])->toBe('review_conflict');
});

test('O12: cancelled case fence denies teacher review decision with 409 review_conflict', function () {
    $setup = setupCaseWithReview($this);
    $case = $setup['case'];
    $reviewItem = $setup['reviewItem'];
    $candidate = $setup['candidate'];

    // Set case to cancelled
    $case->state = 'cancelled';
    $case->save();

    CaseOperationControl::create([
        'case_id' => $case->id,
        'mode' => 'cancelled',
        'control_version' => 1,
        'applied_at' => now(),
    ]);

    $decisionInput = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'candidate_ref' => $candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
    ];

    $submitService = app(SubmitReviewDecision::class);
    $result = $submitService->submit($case, $reviewItem, $decisionInput, $this->teacher);

    expect($result['status'])->toBe(409)
        ->and($result['error']['code'])->toBe('review_conflict');
});

test('O09: signed GET /api/internal/v1/cases/{case_id}/operation-control returns mode and version', function () {
    $caseId = (string) Str::uuid();
    $case = ProcessingCase::forceCreate([
        'id' => $caseId,
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CaseOperationControl::create([
        'case_id' => $caseId,
        'mode' => 'held',
        'control_version' => 2,
    ]);

    $url = "/api/internal/v1/cases/{$caseId}/operation-control";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $url, []);

    $response = $this->withHeaders($headers)->get($url);
    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');
    $response->assertJson([
            'data' => [
                'mode' => 'held',
                'control_version' => 2,
            ],
        ]);

    // Unsigned request returns 401
    $unsignedResp = $this->getJson($url);
    $unsignedResp->assertStatus(401);

    // Nonexistent case returns 404
    $fakeCaseId = (string) Str::uuid();
    $fakeUrl = "/api/internal/v1/cases/{$fakeCaseId}/operation-control";
    $fakeHeaders = WorkflowFixtures::signCallbackRequest('GET', $fakeUrl, []);
    $fakeResp = $this->withHeaders($fakeHeaders)->get($fakeUrl);
    $fakeResp->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('O09: ProjectCaseEvent rejects late worker milestone on cancelled case with 409', function () {
    $setup = setupCaseWithReview($this);
    $case = $setup['case'];

    $case->state = 'cancelled';
    $case->state_version = 3;
    $case->save();

    $projector = app(ProjectCaseEvent::class);
    $eventPayload = [
        'schema_version' => 1,
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'run_id' => 'run-race-test',
        'event_key' => 'late:milestone:test',
        'expected_state_version' => 3,
        'event_type' => 'stage_completed',
        'stage_code' => 'complete',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $result = $projector->project($eventPayload);
    expect($result['status'])->toBe(409)
        ->and($result['error']['code'])->toBe('version_conflict');

    // Case state unchanged
    $case->refresh();
    expect($case->state)->toBe('cancelled')
        ->and($case->state_version)->toBe(3);
});

test('O08: StartWorkflow defers remote call when case operation control is fenced', function () {
    $setup = setupCaseWithReview($this);
    $case = $setup['case'];

    CaseOperationControl::create([
        'case_id' => $case->id,
        'mode' => 'hold_requested',
        'control_version' => 1,
    ]);

    $startWorkflow = app(StartWorkflow::class);
    $result = $startWorkflow->start($case->id);

    expect($result['status'])->toBe('deferred')
        ->and($result['reason'])->toBe('operation_fenced');
});
