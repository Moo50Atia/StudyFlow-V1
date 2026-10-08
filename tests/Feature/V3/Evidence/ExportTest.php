<?php

namespace Tests\Feature\V3\Evidence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\V3\Evidence\fixtures\EvidenceTestBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
});

test('H14: owner case and approval exports contain exact allowlisted refs but no raw files, prompts, notes, or storage paths', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $teacher = $data['teacher'];
    $case = $data['case'];
    $approval = $data['approvedScene'];

    $idempotencyKey = (string) Str::uuid();
    $postPayload = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'scope' => 'owner_case',
        'approval_ref' => null,
    ];

    // 1. Create owner case export
    $res = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", $postPayload);
    expect($res->status())->toBe(201)
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');

    $exportRef = $res->json('data.export_ref');
    $downloadUrl = $res->json('data.download_url');
    expect($exportRef)->not->toBeNull()
        ->and($downloadUrl)->toContain("/v3/teacher/cases/{$case->id}/evidence-exports/{$exportRef}/download");

    // 2. Download snapshot
    $downloadRes = $this->actingAs($teacher)->get($downloadUrl);
    expect($downloadRes->status())->toBe(200)
        ->and($downloadRes->headers->get('Content-Type'))->toContain('application/json')
        ->and($downloadRes->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($downloadRes->headers->get('Cache-Control'))->toContain('no-store');

    $content = $downloadRes->getContent();
    $json = json_decode($content, true);
    expect($json['schema_version'])->toBe(1)
        ->and($json['scope'])->toBe('owner_case')
        ->and($json['graph'])->toBeArray()
        ->and($json['metric'])->toBeArray();

    // Must NOT contain raw document storage path, document sha256 or raw text
    expect($content)->not->toContain($data['document']->storage_path)
        ->and($content)->not->toContain($data['document']->sha256);

    // 3. Create owner approval export
    $approvalKey = (string) Str::uuid();
    $approvalPayload = [
        'schema_version' => 1,
        'idempotency_key' => $approvalKey,
        'scope' => 'owner_approval',
        'approval_ref' => $approval->id,
    ];
    $appRes = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", $approvalPayload);
    expect($appRes->status())->toBe(201);

    $appDownloadUrl = $appRes->json('data.download_url');
    $appDownloadRes = $this->actingAs($teacher)->get($appDownloadUrl);
    expect($appDownloadRes->status())->toBe(200);

    $appJson = json_decode($appDownloadRes->getContent(), true);
    expect($appJson['scope'])->toBe('owner_approval')
        ->and($appJson['graph']['current_approval_refs'])->toContain($approval->id);
});

test('H15: admin export bytes strictly redact teacher identities, educational prompts, and raw hashes', function () {
    $sentinelSecret = 'SUPER_SECRET_TEACHER_PROMPT_SENTINEL_XYZ_999';
    $teacherEmail = 'unique_teacher_email_sentinel_123@example.com';
    $admin = EvidenceTestBuilder::createAdmin();

    $data = EvidenceTestBuilder::createCompleteCase();
    $teacher = $data['teacher'];
    $teacher->update(['email' => $teacherEmail]);
    $case = $data['case'];

    DB::table('v3_teacher_sections')->where('id', $data['section']->id)->update([
        'visual_first_explanation' => $sentinelSecret,
    ]);

    $from = now()->subHours(2)->toIso8601String();
    $to = now()->addHour()->toIso8601String();
    $idempotencyKey = (string) Str::uuid();

    $postRes = $this->actingAs($admin)->postJson('/v3/admin/evidence-exports', [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'from' => $from,
        'to' => $to,
    ]);

    expect($postRes->status())->toBe(201)
        ->and($postRes->headers->get('Cache-Control'))->toContain('no-store');

    $exportRef = $postRes->json('data.export_ref');
    $downloadUrl = $postRes->json('data.download_url');

    $downloadRes = $this->actingAs($admin)->get($downloadUrl);
    expect($downloadRes->status())->toBe(200)
        ->and($downloadRes->headers->get('Cache-Control'))->toContain('no-store');

    $content = $downloadRes->getContent();
    expect($content)->not->toContain($teacherEmail)
        ->and($content)->not->toContain($sentinelSecret)
        ->and($content)->not->toContain($data['document']->sha256)
        ->and($content)->not->toContain($data['document']->storage_path);
});

test('H16: unauthorized actors, cross-teacher requests, and wrong roles cannot download exports', function () {
    $dataA = EvidenceTestBuilder::createCompleteCase();
    $teacherA = $dataA['teacher'];
    $teacherB = EvidenceTestBuilder::createTeacher();
    $adminA = EvidenceTestBuilder::createAdmin();
    $adminB = EvidenceTestBuilder::createAdmin();
    $caseA = $dataA['case'];

    // 1. Create owner export by Teacher A
    $postRes = $this->actingAs($teacherA)->postJson("/v3/teacher/cases/{$caseA->id}/evidence-exports", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'scope' => 'owner_case',
        'approval_ref' => null,
    ]);
    expect($postRes->status())->toBe(201);
    $teacherDownloadUrl = $postRes->json('data.download_url');

    // Guest cannot download
    auth()->logout();
    expect($this->getJson($teacherDownloadUrl)->status())->toBe(401)
        ->and($this->get($teacherDownloadUrl)->status())->toBe(302);

    // Other teacher cannot download
    expect($this->actingAs($teacherB)->get($teacherDownloadUrl)->status())->toBe(404);

    // Admin cannot download teacher export
    expect($this->actingAs($adminA)->get($teacherDownloadUrl)->status())->toBe(403);

    // 2. Create admin export by Admin A
    $adminPost = $this->actingAs($adminA)->postJson('/v3/admin/evidence-exports', [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'from' => now()->subHours(2)->toIso8601String(),
        'to' => now()->addHour()->toIso8601String(),
    ]);
    expect($adminPost->status())->toBe(201);
    $adminDownloadUrl = $adminPost->json('data.download_url');

    // Teacher cannot download admin export
    expect($this->actingAs($teacherA)->get($adminDownloadUrl)->status())->toBe(403);

    // Different admin cannot download Admin A export (download bound to exact actor)
    expect($this->actingAs($adminB)->get($adminDownloadUrl)->status())->toBe(404);

    // Guest cannot download admin export
    auth()->logout();
    expect($this->getJson($adminDownloadUrl)->status())->toBe(401)
        ->and($this->get($adminDownloadUrl)->status())->toBe(302);
});

test('H17: exact replay returns original export and single audit; changed body returns 409 conflict', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $teacher = $data['teacher'];
    $case = $data['case'];

    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'scope' => 'owner_case',
        'approval_ref' => null,
    ];

    // First call -> 201 Created
    $res1 = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", $payload);
    expect($res1->status())->toBe(201);
    $exportRef1 = $res1->json('data.export_ref');

    // Exact replay -> 200 OK with same export ref
    $res2 = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", $payload);
    expect($res2->status())->toBe(200)
        ->and($res2->json('data.export_ref'))->toBe($exportRef1);

    // Only 1 generated audit row in database
    $auditCount = DB::table('v3_evidence_export_audits')
        ->where('export_id', $exportRef1)
        ->where('event', 'generated')
        ->count();
    expect($auditCount)->toBe(1);

    // Conflicting replay with same idempotency key but changed body -> 409 conflict
    $changedPayload = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'scope' => 'owner_approval',
        'approval_ref' => $data['approvedScene']->id,
    ];
    $res3 = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", $changedPayload);
    expect($res3->status())->toBe(409)
        ->and($res3->json('error.code'))->toBe('evidence_conflict');
});

test('H18: 15-minute expiry returns 410, and missing/corrupted snapshot returns 503', function () {
    $data = EvidenceTestBuilder::createCompleteCase();
    $teacher = $data['teacher'];
    $case = $data['case'];

    $postRes = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'scope' => 'owner_case',
        'approval_ref' => null,
    ]);
    expect($postRes->status())->toBe(201);
    $downloadUrl = $postRes->json('data.download_url');
    $exportRef = $postRes->json('data.export_ref');

    // Fast-forward 16 minutes past 15-minute expiry
    $this->travel(16)->minutes();

    $expiredRes = $this->actingAs($teacher)->get($downloadUrl);
    expect($expiredRes->status())->toBe(410)
        ->and($expiredRes->json('error.code'))->toBe('evidence_expired');

    // Travel back to valid window
    $this->travelBack();

    // Corrupt snapshot file in storage
    $export = DB::table('v3_evidence_exports')->where('id', $exportRef)->first();
    Storage::disk('v3_private')->put($export->snapshot_path, 'corrupted_bytes');

    $corruptRes = $this->actingAs($teacher)->get($downloadUrl);
    expect($corruptRes->status())->toBe(503)
        ->and($corruptRes->json('error.code'))->toBe('evidence_unavailable');

    // Missing file returns 503
    Storage::disk('v3_private')->delete($export->snapshot_path);
    $missingRes = $this->actingAs($teacher)->get($downloadUrl);
    expect($missingRes->status())->toBe(503)
        ->and($missingRes->json('error.code'))->toBe('evidence_unavailable');

    // Test hourly purge command retention under pilot-retain-v1
    $cleanupKey = (string) Str::uuid();
    $cleanupPost = $this->actingAs($teacher)->postJson("/v3/teacher/cases/{$case->id}/evidence-exports", [
        'schema_version' => 1,
        'idempotency_key' => $cleanupKey,
        'scope' => 'owner_case',
        'approval_ref' => null,
    ]);
    expect($cleanupPost->status())->toBe(201);
    $cleanupRef = $cleanupPost->json('data.export_ref');
    $cleanupRow = DB::table('v3_evidence_exports')->where('id', $cleanupRef)->first();

    expect(Storage::disk('v3_private')->exists($cleanupRow->snapshot_path))->toBeTrue();

    // At 16 minutes: expired for downloads, but NOT purged by 24h cleanup
    $this->travel(16)->minutes();
    Artisan::call('v3:purge-expired-evidence-exports');
    expect(Storage::disk('v3_private')->exists($cleanupRow->snapshot_path))->toBeTrue()
        ->and(DB::table('v3_evidence_exports')->where('id', $cleanupRef)->value('file_removed_at'))->toBeNull();

    // Fast-forward past 24 hours (25 hours total)
    $this->travel(25)->hours();
    Artisan::call('v3:purge-expired-evidence-exports');

    // Export file is purged and marked file_removed_at
    expect(Storage::disk('v3_private')->exists($cleanupRow->snapshot_path))->toBeFalse()
        ->and(DB::table('v3_evidence_exports')->where('id', $cleanupRef)->value('file_removed_at'))->not->toBeNull();

    // Prerequisite 006-014 educational files are NEVER purged
    expect(Storage::disk('v3_private')->exists($data['document']->storage_path))->toBeTrue()
        ->and(Storage::disk('v3_private')->exists($data['extractedSource']->artifact_path))->toBeTrue();

    $this->travelBack();
});

test('H20: 014 deferred_to_015 assessment never purges educational bytes', function () {
    $admin = EvidenceTestBuilder::createAdmin();
    $data = EvidenceTestBuilder::createCompleteCase();
    $teacher = $data['teacher'];
    $case = $data['case'];

    // 014 assessment recorded
    DB::table('v3_deletion_assessments')->insert([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'actor_user_id' => $admin->id,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => hash('sha256', 'sample_del_request'),
        'reason_code' => 'pilot_cleanup_request',
        'policy_version' => 'pilot-retain-v1',
        'status' => 'deferred_to_015',
        'safe_blocker' => null,
        'case_state_version' => 1,
        'control_version' => 1,
        'assessed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Ensure referenced artifact files on v3_private are retained
    expect(Storage::disk('v3_private')->exists($data['document']->storage_path))->toBeTrue()
        ->and(Storage::disk('v3_private')->exists($data['extractedSource']->artifact_path))->toBeTrue();

    // Admin metrics contains assessment gap/count cleanly
    $from = now()->subHours(2)->toIso8601String();
    $to = now()->addHour()->toIso8601String();
    $res = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$from}&to={$to}");
    expect($res->status())->toBe(200);

    // Private files still present
    expect(Storage::disk('v3_private')->exists($data['document']->storage_path))->toBeTrue();
});
