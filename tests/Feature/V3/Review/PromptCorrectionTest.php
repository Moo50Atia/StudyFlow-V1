<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherPromptCorrection;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $this->env['teacher'];
    $this->case = $this->env['case'];
    $this->map = $this->env['map'];

    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-factual-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $this->q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $this->section = $this->q1['section'];
    $this->candidate = $this->q1['candidate'];
    $this->fromPrompt = ScenePromptVersion::find($this->candidate->prompt_version_id);

    // Ensure review ready
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
    ]);
    expect($readyRes->status())->toBe(201);
    $this->reviewRef = $readyRes->json('data.review_ref');
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);
});

test('R19, R21: Worker claims teacher prompt correction for factual note, gets context, and records valid result', function () {
    // 1. Teacher submits request_changes with factual_source
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();
    $noteText = "Please update the diagram equation to show gravitational acceleration.";
    $decRes = $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'factual_source',
        'notes' => $noteText,
    ]);
    expect($decRes->status())->toBe(201);
    $routeRef = $decRes->json('data.route_ref');
    expect($routeRef)->not->toBeNull();

    $route = ReviewRoute::find($routeRef);
    expect($route->kind)->toBe('prompt_requalification');

    // 2. Claim prompt correction via signed internal endpoint
    $claimUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$routeRef}/claim";
    $claimRes = ReviewFixtures::postSigned($this, $claimUrl, [
        'schema_version' => 1,
    ]);
    expect($claimRes->status())->toBe(201)
        ->and($claimRes->json('data.effect_ref'))->not->toBeNull()
        ->and($claimRes->json('data.disposition'))->toBe('send_now');

    $effectRef = $claimRes->json('data.effect_ref');

    // Duplicate claim reconciles without re-sending
    $dupRes = ReviewFixtures::postSigned($this, $claimUrl, [
        'schema_version' => 1,
    ]);
    expect($dupRes->status())->toBe(200)
        ->and($dupRes->json('data.effect_ref'))->toBe($effectRef)
        ->and($dupRes->json('data.disposition'))->toBe('reconcile');

    // 3. Fetch correction context via signed internal endpoint
    $contextUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$effectRef}/context";
    $ctxRes = ReviewFixtures::getSigned($this, $contextUrl);
    expect($ctxRes->status())->toBe(200);

    $ctxData = $ctxRes->json('data');
    expect(array_keys($ctxData))->toEqualCanonicalizing([
        'effect_ref',
        'route_ref',
        'section_ref',
        'work_ordinal',
        'from_prompt_version_ref',
        'previous_prompt',
        'fields',
        'teacher_note',
        'previous_qualification',
        'template_id',
        'template_sha256',
        'template_bytes',
        'provider',
        'settings_version_id',
        'settings_checksum',
        'revision_count',
        'revision_limit',
        'request_sha256',
    ]);

    expect($ctxData['teacher_note'])->toBe($noteText)
        ->and($ctxData['from_prompt_version_ref'])->toBe($this->fromPrompt->id)
        ->and($ctxData['section_ref'])->toBe($this->section->id)
        ->and($ctxData['provider']['id'])->toBe('gemini_api')
        ->and($ctxData['provider']['model'])->toBe('gemini-2.5-flash')
        ->and(count($ctxData['fields']))->toBe(11);

    // 4. Record valid result from provider
    $revisedPrompt = "Process: An updated animated simulation illustrating gravitational acceleration with accurate formulas.";
    $outputSha = hash('sha256', $revisedPrompt);
    $respJson = json_encode(['candidates' => [['content' => ['parts' => [['text' => $revisedPrompt]]]]]]);
    $respSha = hash('sha256', $respJson);

    $resultUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$effectRef}/result";
    $resRes = ReviewFixtures::postSigned($this, $resultUrl, [
        'schema_version' => 1,
        'revised_prompt' => $revisedPrompt,
        'output_sha256' => $outputSha,
        'provider_response_json' => $respJson,
        'provider_response_sha256' => $respSha,
        'returned_model' => 'gemini-2.5-flash',
        'provider_response_id' => 'resp-test-123',
    ]);

    expect($resRes->status())->toBe(201)
        ->and($resRes->json('data.version_no'))->toBe(2)
        ->and($resRes->json('data.state'))->toBe('awaiting_qualification');

    $newPromptRef = $resRes->json('data.prompt_version_ref');
    expect($newPromptRef)->not->toBeNull();

    // Verify ScenePromptVersion in DB
    $newPrompt = ScenePromptVersion::find($newPromptRef);
    expect($newPrompt)->not->toBeNull()
        ->and($newPrompt->version_no)->toBe(2)
        ->and($newPrompt->predecessor_id)->toBe($this->fromPrompt->id)
        ->and($newPrompt->origin)->toBe('teacher_correction')
        ->and($newPrompt->teacher_correction_id)->toBe($effectRef)
        ->and($newPrompt->feedback_effect_id)->toBeNull()
        ->and($newPrompt->scene_type)->toBe('Process');

    // Verify PromptSectionState updated
    $secState = PromptSectionState::where('section_id', $this->section->id)->first();
    expect($secState->current_prompt_version_id)->toBe($newPromptRef)
        ->and($secState->revision_count)->toBe(1)
        ->and($secState->status)->toBe('awaiting_qualification');

    // Verify ReviewRoute marked completed
    $route->refresh();
    expect($route->state)->toBe('completed')
        ->and($route->target_ref)->toBe($newPromptRef);

    // Exact replay returns 200 with same result
    $replayRes = ReviewFixtures::postSigned($this, $resultUrl, [
        'schema_version' => 1,
        'revised_prompt' => $revisedPrompt,
        'output_sha256' => $outputSha,
        'provider_response_json' => $respJson,
        'provider_response_sha256' => $respSha,
        'returned_model' => 'gemini-2.5-flash',
        'provider_response_id' => 'resp-test-123',
    ]);
    expect($replayRes->status())->toBe(200)
        ->and($replayRes->json('data.prompt_version_ref'))->toBe($newPromptRef);

    // Conflicting result on recorded effect returns 409
    $conflictRes = ReviewFixtures::postSigned($this, $resultUrl, [
        'schema_version' => 1,
        'revised_prompt' => 'Process: Conflicting text completely different.',
        'output_sha256' => hash('sha256', 'Process: Conflicting text completely different.'),
        'provider_response_json' => json_encode(['different' => true]),
        'provider_response_sha256' => hash('sha256', json_encode(['different' => true])),
        'returned_model' => 'gemini-2.5-flash',
        'provider_response_id' => 'resp-test-diff',
    ]);
    expect($conflictRes->status())->toBe(409);
});

test('R19, R20: Prompt revision budget 0 or exhausted blocks route and candidate without send', function () {
    // Exhaust prompt revision budget
    $secState = PromptSectionState::where('section_id', $this->section->id)->first();
    $secState->update(['revision_count' => 2]); // budget limit is 2

    // Submit request changes with mixed feedback
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();
    $decRes = $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'mixed',
        'notes' => 'Fix calculation and increase contrast.',
    ]);
    expect($decRes->status())->toBe(201);
    $routeRef = $decRes->json('data.route_ref');

    // Attempt claim
    $claimUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$routeRef}/claim";
    $claimRes = ReviewFixtures::postSigned($this, $claimUrl, [
        'schema_version' => 1,
    ]);
    expect($claimRes->status())->toBe(409);

    // Verify route and candidate are blocked
    $route = ReviewRoute::find($routeRef);
    expect($route->state)->toBe('blocked')
        ->and($route->error_code)->toBe('validation');

    $candState = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($candState->status)->toBe('blocked')
        ->and($candState->safe_error_code)->toBe('validation');
});

test('R20: Post-send timeout marks correction uncertain and never auto-resends', function () {
    // 1. Submit request_changes
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();
    $decRes = $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'factual_source',
        'notes' => 'Fix factual inaccuracy in explanation text.',
    ]);
    $routeRef = $decRes->json('data.route_ref');

    // 2. Claim
    $claimUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$routeRef}/claim";
    $claimRes = ReviewFixtures::postSigned($this, $claimUrl, ['schema_version' => 1]);
    expect($claimRes->status())->toBe(201);
    $effectRef = $claimRes->json('data.effect_ref');

    // 3. Mark uncertain (timeout)
    $uncUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$effectRef}/uncertain";
    $uncRes = ReviewFixtures::postSigned($this, $uncUrl, [
        'schema_version' => 1,
        'error_code' => 'timeout',
    ]);
    expect($uncRes->status())->toBe(200)
        ->and($uncRes->json('data.state'))->toBe('uncertain');

    // Verify route and effect marked uncertain
    $route = ReviewRoute::find($routeRef);
    expect($route->state)->toBe('uncertain')
        ->and($route->error_code)->toBe('timeout');

    // Calling claim again returns reconcile and does NOT auto-resend (send_now)
    $reclaimRes = ReviewFixtures::postSigned($this, $claimUrl, ['schema_version' => 1]);
    expect($reclaimRes->status())->toBe(200)
        ->and($reclaimRes->json('data.disposition'))->toBe('reconcile');
});

test('R21: Known invalid output records blocked/validation with no successor', function () {
    // Submit request_changes
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $decRes = $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'factual_source',
        'notes' => 'Correct speed of light constant.',
    ]);
    $routeRef = $decRes->json('data.route_ref');

    $claimUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$routeRef}/claim";
    $claimRes = ReviewFixtures::postSigned($this, $claimUrl, ['schema_version' => 1]);
    $effectRef = $claimRes->json('data.effect_ref');

    // Submit invalid result (not starting with allowed scene type)
    $invalidPrompt = "This does not start with any allowed scene type.";
    $outputSha = hash('sha256', $invalidPrompt);
    $respJson = json_encode(['text' => $invalidPrompt]);
    $respSha = hash('sha256', $respJson);

    $resultUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-prompt-corrections/{$effectRef}/result";
    $resRes = ReviewFixtures::postSigned($this, $resultUrl, [
        'schema_version' => 1,
        'revised_prompt' => $invalidPrompt,
        'output_sha256' => $outputSha,
        'provider_response_json' => $respJson,
        'provider_response_sha256' => $respSha,
        'returned_model' => 'gemini-2.5-flash',
        'provider_response_id' => 'resp-invalid',
    ]);
    expect($resRes->status())->toBeIn([422, 409]);

    // Verify route and effect marked blocked
    $route = ReviewRoute::find($routeRef);
    expect($route->state)->toBe('blocked')
        ->and($route->error_code)->toBe('validation');

    // Verify no successor created
    expect(ScenePromptVersion::where('predecessor_id', $this->fromPrompt->id)->count())->toBe(0);
});
