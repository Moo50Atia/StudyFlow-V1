<?php

namespace Tests\Feature\V3\Evidence;

use App\Models\V3\DeletionAssessment;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\V3\Evidence\fixtures\EvidenceTestBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
});

test('H06: guest, inactive teacher, admin, and foreign teacher are denied from owner history and approval trace with no-store', function () {
    $caseData = EvidenceTestBuilder::createCompleteCase();
    $case = $caseData['case'];
    $approval = $caseData['approvedScene'];
    $otherTeacher = EvidenceTestBuilder::createTeacher();
    $admin = EvidenceTestBuilder::createAdmin();

    $historyUrl = "/v3/teacher/cases/{$case->id}/evidence";
    $approvalUrl = "/v3/teacher/cases/{$case->id}/evidence/approvals/{$approval->id}";

    // Guest request
    $guestRes = $this->getJson($historyUrl);
    expect($guestRes->status())->toBe(401)
        ->and($guestRes->headers->get('Cache-Control'))->toContain('no-store');

    // Admin request to teacher route
    $adminRes = $this->actingAs($admin)->getJson($historyUrl);
    expect($adminRes->status())->toBe(403)
        ->and($adminRes->headers->get('Cache-Control'))->toContain('no-store');

    // Other teacher request (must be 404 resource_unavailable, indistinguishable from nonexistent)
    $otherTeacherRes = $this->actingAs($otherTeacher)->getJson($historyUrl);
    expect($otherTeacherRes->status())->toBe(404)
        ->and($otherTeacherRes->json('error.code'))->toBe('resource_unavailable')
        ->and($otherTeacherRes->headers->get('Cache-Control'))->toContain('no-store');

    // Inactive teacher
    $inactiveTeacher = EvidenceTestBuilder::createTeacher();
    DB::table('v3_account_access')->where('user_id', $inactiveTeacher->id)->update(['is_active' => false]);
    $inactiveRes = $this->actingAs($inactiveTeacher)->getJson($historyUrl);
    expect($inactiveRes->status())->toBe(403)
        ->and($inactiveRes->headers->get('Cache-Control'))->toContain('no-store');

    // Foreign approval on own case returns 404 resource_unavailable
    $foreignApprovalId = (string) Str::uuid();
    $foreignAppRes = $this->actingAs($caseData['teacher'])->getJson("/v3/teacher/cases/{$case->id}/evidence/approvals/{$foreignApprovalId}");
    expect($foreignAppRes->status())->toBe(404)
        ->and($foreignAppRes->json('error.code'))->toBe('resource_unavailable')
        ->and($foreignAppRes->headers->get('Cache-Control'))->toContain('no-store');
});

test('H07: prerequisite routes and isolation prevent cross-owner evidence leakage and owner lists are strictly scoped', function () {
    $case1 = EvidenceTestBuilder::createCompleteCase();
    $case2 = EvidenceTestBuilder::createCompleteCase();

    // Teacher 1 cannot access Teacher 2 document download
    $resDoc = $this->actingAs($case1['teacher'])->getJson("/v3/teacher/documents/{$case2['document']->id}/download");
    expect($resDoc->status())->toBe(404)
        ->and($resDoc->headers->get('Cache-Control'))->toContain('no-store');

    // Teacher 1 cannot access Teacher 2 lesson map
    $resMap = $this->actingAs($case1['teacher'])->getJson("/v3/teacher/cases/{$case2['case']->id}/lesson-map");
    expect($resMap->status())->toBe(404);

    // Teacher 1 cannot access Teacher 2 section
    $resSec = $this->actingAs($case1['teacher'])->getJson("/v3/teacher/cases/{$case2['case']->id}/sections/{$case2['section']->id}");
    expect($resSec->status())->toBe(404);
});

test('H01: active original teacher traces a current 013 approval with complete lineage integrity', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $case = $data['case'];
    $approval = $data['approvedScene'];
    $teacher = $data['teacher'];

    $url = "/v3/teacher/cases/{$case->id}/evidence/approvals/{$approval->id}";
    $res = $this->actingAs($teacher)->getJson($url);

    expect($res->status())->toBe(200)
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');

    $graph = $res->json('data');
    expect($graph['case_ref'])->toBe($case->id)
        ->and($graph['approval_ref'])->toBe($approval->id)
        ->and($graph['current'])->toBeTrue()
        ->and($graph['integrity'])->toBe('complete')
        ->and($graph['current_approval_refs'])->toContain($approval->id)
        ->and($graph['historical_approval_refs'])->toBeEmpty()
        ->and($graph['gaps'])->toBeEmpty();

    $nodeKinds = collect($graph['nodes'])->pluck('kind')->all();
    expect($nodeKinds)->toContain(
        'document',
        'settings_version',
        'extracted_source',
        'knowledge_source_binding',
        'lesson_map',
        'teacher_section',
        'prompt_version',
        'prompt_qualification',
        'authoring_request',
        'coding_attempt',
        'scene_candidate',
        'scene_check_run',
        'scene_qualification',
        'teacher_review_item',
        'teacher_review_decision',
        'approved_scene'
    );

    // Check link relations exist and form valid chain
    $relations = collect($graph['links'])->pluck('relation')->all();
    expect($relations)->toContain(
        'review_decision_id',
        'review_item_id',
        'candidate_id',
        'qualification_id',
        'check_run_id',
        'request_id',
        'prompt_version_id',
        'section_id',
        'map_id',
        'binding_id',
        'extracted_source_id',
        'document_id'
    );
});

test('H02: post-approval correction clears current pointer, keeping old approval traceable as historical', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $case = $data['case'];
    $approval = $data['approvedScene'];
    $teacher = $data['teacher'];
    $section = $data['section'];

    // Create a post-approval correction
    $correction = PostapprovalCorrection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'approval_id' => $approval->id,
        'actor_user_id' => $teacher->id,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => hash('sha256', 'correction request'),
        'feedback_kind' => 'factual_source',
        'note_path' => 'notes/correction.txt',
        'note_sha256' => hash('sha256', 'notes'),
        'note_bytes' => 5,
        'state' => 'accepted',
        'created_at' => now()->subMinutes(2),
    ]);

    // Clear current approval pointer to simulate post-approval correction lifecycle
    ReviewSectionState::where('section_id', $section->id)->update([
        'current_approval_id' => null,
    ]);

    $url = "/v3/teacher/cases/{$case->id}/evidence/approvals/{$approval->id}";
    $res = $this->actingAs($teacher)->getJson($url);

    expect($res->status())->toBe(200);
    $graph = $res->json('data');

    expect($graph['current'])->toBeFalse()
        ->and($graph['current_approval_refs'])->toBeEmpty()
        ->and($graph['historical_approval_refs'])->toContain($approval->id)
        ->and($graph['integrity'])->toBe('complete');

    // The node for the approved scene is marked historical
    $approvedNode = collect($graph['nodes'])->firstWhere('ref', $approval->id);
    expect($approvedNode)->not->toBeNull()
        ->and($approvedNode['state'])->toBe('historical');
});

test('H03: failed and uncertain attempts and 014 operations remain in timeline and do not become approval ancestors', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $case = $data['case'];
    $teacher = $data['teacher'];
    $approval = $data['approvedScene'];

    // Sibling failed JEV attempt
    $failedJev = PromptJevAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'prompt_version_id' => $data['promptVersion']->id,
        'attempt_no' => 2,
        'request_sha256' => hash('sha256', 'failed req'),
        'question_sha256' => hash('sha256', 'failed q'),
        'requested_model' => 'gemini-2.5-pro',
        'settings_version_id' => $data['settings']->id,
        'state' => 'technical_failure',
        'error_code' => 'timeout',
        'sent_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    // 014 Deletion assessment
    $deletionAssessment = DeletionAssessment::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'actor_user_id' => $teacher->id,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => hash('sha256', 'deletion assessment request'),
        'reason_code' => 'pilot_cleanup_request',
        'policy_version' => 'pilot-retain-v1',
        'status' => 'deferred_to_015',
        'safe_blocker' => 'review_pending',
        'case_state_version' => 1,
        'control_version' => 1,
        'assessed_at' => now()->subMinutes(10),
    ]);

    // Approval trace does NOT have failed attempt in direct ancestors
    $appUrl = "/v3/teacher/cases/{$case->id}/evidence/approvals/{$approval->id}";
    $appRes = $this->actingAs($teacher)->getJson($appUrl);
    expect($appRes->status())->toBe(200);

    // Case timeline DOES include case events and 014 operations
    $timelineUrl = "/v3/teacher/cases/{$case->id}/evidence";
    $timelineRes = $this->actingAs($teacher)->getJson($timelineUrl);
    expect($timelineRes->status())->toBe(200);

    $events = $timelineRes->json('data.events');
    $eventRefs = collect($events)->pluck('ref')->all();
    expect($eventRefs)->toContain($deletionAssessment->id);
});

test('H04: mismatched candidate or section parent yields typed gap and incomplete integrity', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $case = $data['case'];
    $teacher = $data['teacher'];
    $approval = $data['approvedScene'];

    // Create another section and make candidate section_id mismatch authoring_request section_id
    $other = \Tests\Support\V3\AuthoringFixtures::createQualifiedSection($case, $data['lessonMap'], 2);
    $otherSection = $other['section'];

    // Make candidate section_id mismatch authoring_request section_id
    SceneCandidate::where('id', $data['candidate']->id)->update([
        'section_id' => $otherSection->id,
    ]);

    $url = "/v3/teacher/cases/{$case->id}/evidence/approvals/{$approval->id}";
    $res = $this->actingAs($teacher)->getJson($url);
    expect($res->status())->toBe(200);

    $graph = $res->json('data');
    expect($graph['integrity'])->toBe('incomplete')
        ->and(collect($graph['gaps'])->pluck('code')->all())->toContain('parent_mismatch');
});

test('H05: missing or corrupt referenced private file yields missing_or_corrupt, fixed gap, and incomplete integrity', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $case = $data['case'];
    $teacher = $data['teacher'];
    $approval = $data['approvedScene'];

    // Delete candidate manifest file from storage
    Storage::disk('v3_private')->delete($data['candidate']->manifest_path);

    $url = "/v3/teacher/cases/{$case->id}/evidence/approvals/{$approval->id}";
    $res = $this->actingAs($teacher)->getJson($url);
    expect($res->status())->toBe(200);

    $graph = $res->json('data');
    expect($graph['integrity'])->toBe('incomplete');

    $candidateNode = collect($graph['nodes'])->firstWhere('ref', $data['candidate']->id);
    expect($candidateNode['content_state'])->toBe('missing_or_corrupt');

    $gaps = collect($graph['gaps'])->pluck('code')->all();
    expect($gaps)->toContain('missing_file');
});
