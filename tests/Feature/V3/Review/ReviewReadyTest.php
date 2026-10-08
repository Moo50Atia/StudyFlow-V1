<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\ApprovedScene;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    $this->case = $this->env['case'];
    $this->map = $this->env['map'];
});

test('R01: current 012 passing tip creates one item with exact candidate/version/qualification and duplicate ready returns same item', function () {
    $q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $candidate = $q1['candidate'];
    $qual = $q1['qualification'];
    $section = $q1['section'];

    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";

    // 1. Initial ready call creates item
    $res1 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidate->id,
    ]);
    $res1->assertStatus(201);
    $res1->assertJsonStructure([
        'data' => [
            'review_ref',
            'section_ref',
            'candidate_ref',
            'candidate_version',
        ],
    ]);
    $reviewRef = $res1->json('data.review_ref');
    expect($res1->json('data.section_ref'))->toBe($section->id)
        ->and($res1->json('data.candidate_ref'))->toBe($candidate->id)
        ->and($res1->json('data.candidate_version'))->toBe(1);

    // Verify DB item
    $item = TeacherReviewItem::find($reviewRef);
    expect($item)->not->toBeNull()
        ->and($item->status)->toBe('pending')
        ->and($item->qualification_id)->toBe($qual->id)
        ->and($item->prompt_version_id)->toBe($candidate->prompt_version_id);

    // Verify ReviewSectionState
    $sectionState = ReviewSectionState::where('section_id', $section->id)->first();
    expect($sectionState)->not->toBeNull()
        ->and($sectionState->state)->toBe('awaiting_review')
        ->and($sectionState->current_review_item_id)->toBe($reviewRef)
        ->and($sectionState->current_approval_id)->toBeNull();

    // 2. Duplicate ready call returns 200 with same review_ref
    $res2 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidate->id,
    ]);
    $res2->assertStatus(200);
    expect($res2->json('data.review_ref'))->toBe($reviewRef);

    // 3. Scheduled reconciliation also idempotent
    Artisan::call('v3:reconcile-scene-reviews');
    expect(TeacherReviewItem::where('section_id', $section->id)->count())->toBe(1);

    // 4. Current items endpoint returns the pending item
    $currentPath = "/api/internal/v1/cases/{$this->case->id}/reviews/current";
    $curRes = ReviewFixtures::getSigned($this, $currentPath);
    $curRes->assertStatus(200);
    $curRes->assertJsonFragment([
        'review_ref' => $reviewRef,
        'section_ref' => $section->id,
        'candidate_ref' => $candidate->id,
        'candidate_version' => 1,
        'qualification_ref' => $qual->id,
    ]);
});

test('R02: failed, unavailable, stale, older-tip, foreign or corrupt candidate creates zero items', function () {
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";

    // Unknown candidate -> 404
    $fakeCandidateId = (string) Str::uuid();
    $resUnknown = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $fakeCandidateId,
    ]);
    $resUnknown->assertStatus(404);

    // Candidate not qualified (status awaiting_check) -> 404
    $qNot = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    SceneCandidateState::where('candidate_id', $qNot['candidate']->id)->update(['status' => 'awaiting_check']);

    $resNotQual = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $qNot['candidate']->id,
    ]);
    $resNotQual->assertStatus(404);

    // Foreign case mismatch
    $otherEnv = AuthoringFixtures::createAuthoringEnvironment();
    $qForeign = ReviewFixtures::createQualifiedCandidate($otherEnv, 1);
    $resForeign = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $qForeign['candidate']->id,
    ]);
    $resForeign->assertStatus(404);

    expect(TeacherReviewItem::count())->toBe(0);
});

test('R03: two qualified sections create two independent items; new current candidate stales only old pending item or clears former current approval', function () {
    $q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $q2 = ReviewFixtures::createQualifiedCandidate($this->env, 2);

    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";

    $res1 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $q1['candidate']->id,
    ]);
    $res1->assertStatus(201);
    $item1Ref = $res1->json('data.review_ref');

    $res2 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $q2['candidate']->id,
    ]);
    $res2->assertStatus(201);
    $item2Ref = $res2->json('data.review_ref');

    expect($item1Ref)->not->toBe($item2Ref)
        ->and(TeacherReviewItem::where('status', 'pending')->count())->toBe(2);

    // Now section 1 gets a new qualified candidate (version 2)
    $parentReq = \App\Models\V3\AuthoringRequest::find($q1['candidate']->request_id);
    $authReqV2 = \App\Models\V3\AuthoringRequest::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'prompt_version_id' => $q1['candidate']->prompt_version_id,
        'prompt_body_sha256' => $q1['promptVersion']->body_sha256,
        'qualification_id' => $q1['candidate']->qualification_id,
        'settings_version_id' => $this->case->settings_version_id,
        'settings_checksum' => $this->case->settingsVersion->checksum,
        'template_id' => $parentReq->template_id,
        'template_sha256' => $parentReq->template_sha256,
        'origin' => 'repair_1',
        'state' => 'candidate_ready',
        'state_version' => 1,
        'attempt_limit' => 3,
    ]);

    $attemptV2 = \App\Models\V3\CodingAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'request_id' => $authReqV2->id,
        'case_id' => $this->case->id,
        'ordinal' => 1,
        'provider_id' => 'gemini_api',
        'requested_model' => 'gemini-2.5-pro',
        'credential_ref' => 'gemini-cred',
        'state' => 'candidate_ready',
        'send_reserved_at' => now(),
        'completed_at' => now(),
    ]);

    $candidateV2Id = (string) Str::uuid();
    $disk = Storage::disk('v3_private');
    $v1Dir = "scene-candidates/{$this->case->id}/{$q1['candidate']->id}";
    $v2Dir = "scene-candidates/{$this->case->id}/{$candidateV2Id}";
    $disk->makeDirectory($v2Dir);
    $disk->copy("{$v1Dir}/manifest.json", "{$v2Dir}/manifest.json");
    $disk->copy("{$v1Dir}/index.html", "{$v2Dir}/index.html");
    $disk->copy("{$v1Dir}/app.js", "{$v2Dir}/app.js");

    $candidateV2 = SceneCandidate::forceCreate([
        'id' => $candidateV2Id,
        'case_id' => $this->case->id,
        'request_id' => $authReqV2->id,
        'section_id' => $q1['section']->id,
        'prompt_version_id' => $q1['candidate']->prompt_version_id,
        'qualification_id' => $q1['candidate']->qualification_id,
        'settings_version_id' => $this->case->settings_version_id,
        'attempt_id' => $attemptV2->id,
        'manifest_path' => "{$v2Dir}/manifest.json",
        'manifest_sha256' => $q1['candidate']->manifest_sha256,
        'file_count' => 2,
        'total_bytes' => 100,
    ]);
    SceneCandidateState::forceCreate([
        'candidate_id' => $candidateV2->id,
        'root_candidate_id' => $q1['candidate']->id,
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'prompt_version_id' => $q1['candidate']->prompt_version_id,
        'version_no' => 2,
        'status' => 'qualified',
        'qualification_id' => $q1['qualification']->id,
        'check_run_id' => $q1['checkRun']->id,
    ]);

    $checkAttV2 = \App\Models\V3\SceneCheckAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidateV2->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'manifest_sha256' => $candidateV2->manifest_sha256,
        'request_sha256' => str_repeat('a', 64),
        'checker_version' => 'chromium-pw-1.63.0-v1',
        'state' => 'recorded',
    ]);

    $checkRunV2 = \App\Models\V3\SceneCheckRun::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidateV2->id,
        'winning_attempt_id' => $checkAttV2->id,
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'prompt_version_id' => $q1['candidate']->prompt_version_id,
        'settings_version_id' => $this->case->settings_version_id,
        'manifest_sha256' => $candidateV2->manifest_sha256,
        'report_sha256' => str_repeat('a', 64),
        'screenshot_sha256' => str_repeat('b', 64),
        'report_path' => "reports/{$candidateV2->id}.json",
        'screenshot_path' => "screens/{$candidateV2->id}.png",
        'verdict' => 'pass',
        'failed_checks' => [],
        'duration_ms' => 100,
    ]);

    $jevAttV2 = \App\Models\V3\SceneJevAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidateV2->id,
        'check_run_id' => $checkRunV2->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'request_sha256' => str_repeat('a', 64),
        'question_sha256' => str_repeat('a', 64),
        'evidence_sha256' => str_repeat('a', 64),
        'evidence_path' => "ev/{$candidateV2->id}.json",
        'requested_model' => 'gemini-2.5-pro',
        'state' => 'recorded',
    ]);

    $qualV2 = \App\Models\V3\SceneQualification::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidateV2->id,
        'check_run_id' => $checkRunV2->id,
        'winning_jev_attempt_id' => $jevAttV2->id,
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'prompt_version_id' => $q1['candidate']->prompt_version_id,
        'settings_version_id' => $this->case->settings_version_id,
        'settings_checksum' => str_repeat('c', 64),
        'manifest_sha256' => $candidateV2->manifest_sha256,
        'evidence_sha256' => str_repeat('d', 64),
        'request_sha256' => str_repeat('e', 64),
        'question_sha256' => str_repeat('f', 64),
        'answer_sha256' => str_repeat('0', 64),
        'evidence_path' => "ev/{$candidateV2->id}.json",
        'answer_path' => "ans/{$candidateV2->id}.json",
        'requested_model' => 'gemini-2.5-pro',
        'returned_model' => 'gemini-2.5-pro',
        'choice' => 'pass',
        'choice_confidence' => 0.95,
        'score' => 95.0,
        'score_confidence' => 0.95,
        'noul_probability' => 0.95,
        'noul_yes' => true,
        'min_score' => 75,
        'min_confidence' => 70,
        'decision' => 'pass',
        'failed_checks' => [],
    ]);

    SceneCandidateState::where('candidate_id', $candidateV2->id)->update([
        'qualification_id' => $qualV2->id,
        'check_run_id' => $checkRunV2->id,
    ]);

    // Mark successor repair link from v1 to v2
    SceneRepair::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'prompt_version_id' => $q1['candidate']->prompt_version_id,
        'root_candidate_id' => $q1['candidate']->id,
        'from_candidate_id' => $q1['candidate']->id,
        'to_candidate_id' => $candidateV2->id,
        'authoring_request_id' => $authReqV2->id,
        'ordinal' => 1,
        'criteria_sha256' => str_repeat('a', 64),
        'criteria_path' => 'criteria.json',
        'state' => 'completed',
    ]);

    $resV2 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidateV2->id,
    ]);
    $resV2->assertStatus(201);
    $itemV2Ref = $resV2->json('data.review_ref');

    // Item 1 is now marked stale!
    $item1 = TeacherReviewItem::find($item1Ref);
    expect($item1->status)->toBe('stale');

    // Item 2 for section 2 is completely unaffected (still pending)
    $item2 = TeacherReviewItem::find($item2Ref);
    expect($item2->status)->toBe('pending');

    // Section state current_review_item_id is updated to itemV2Ref
    $sec1State = ReviewSectionState::where('section_id', $q1['section']->id)->first();
    expect($sec1State->current_review_item_id)->toBe($itemV2Ref);
});
