<?php

namespace Tests\Feature\V3\Evidence;

use App\Models\V3\ReviewSectionState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\V3\Evidence\fixtures\EvidenceTestBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
});

test('H15 (Admin Route Redaction): admin metrics route contains only operational fields and strictly redacts teacher and educational content', function () {
    $sentinelSecret = 'SUPER_SECRET_TEACHER_PROMPT_SENTINEL_XYZ_999';
    $teacherEmail = 'unique_teacher_email_sentinel_123@example.com';
    $teacher = EvidenceTestBuilder::createTeacher($teacherEmail);
    $admin = EvidenceTestBuilder::createAdmin();

    $data = EvidenceTestBuilder::createCompleteCase(['teacher' => $teacher]);
    $case = $data['case'];

    // Update section with sentinel content to test redaction
    \Illuminate\Support\Facades\DB::table('v3_teacher_sections')->where('id', $data['section']->id)->update([
        'visual_first_explanation' => $sentinelSecret,
    ]);

    $from = now()->subHours(2)->toIso8601String();
    $to = now()->addHour()->toIso8601String();

    $url = "/v3/admin/evidence/metrics?from={$from}&to={$to}";
    $res = $this->actingAs($admin)->getJson($url);

    expect($res->status())->toBe(200)
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');

    $content = $res->getContent();
    // Must NOT leak teacher email, sentinel prompt, document filename or hash
    expect($content)->not->toContain($teacherEmail)
        ->and($content)->not->toContain($sentinelSecret)
        ->and($content)->not->toContain($data['document']->sha256)
        ->and($content)->not->toContain($data['document']->storage_path);

    // Guest and teacher cannot access
    auth()->logout();
    $guestRes = $this->getJson($url);
    expect($guestRes->status())->toBe(401);

    $teacherRes = $this->actingAs($teacher)->getJson($url);
    expect($teacherRes->status())->toBe(403);
});

test('H08: frozen UTC case and event times produce exact case elapsed and stage residence', function () {
    $now = Carbon::create(2026, 10, 4, 12, 0, 0, 'UTC');
    Carbon::setTestNow($now);

    $admin = EvidenceTestBuilder::createAdmin();
    $data = EvidenceTestBuilder::createCompleteCase();
    $case = $data['case'];

    $from = $now->copy()->subHours(2)->toIso8601String();
    $to = $now->copy()->addHours(2)->toIso8601String();

    $res = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$from}&to={$to}");
    expect($res->status())->toBe(200);

    $report = $res->json('data');
    expect($report['case_count'])->toBe(1)
        ->and($report['case_elapsed_ms']['known_count'])->toBe(1)
        ->and($report['case_elapsed_ms']['sum'])->toBeGreaterThan(0)
        ->and($report['case_elapsed_ms']['definition'])->toContain('creation');

    expect($report['stage_residence_ms'])->toBeArray()
        ->and(array_keys($report['stage_residence_ms']))->toContain('source_extraction', 'terminal');
});

test('H09: measured attempt duration is separate from wall elapsed, and review wait is labeled wait', function () {
    $admin = EvidenceTestBuilder::createAdmin();
    $data = EvidenceTestBuilder::createCompleteCase();

    $from = now()->subHours(2)->toIso8601String();
    $to = now()->addHours(2)->toIso8601String();

    $res = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$from}&to={$to}");
    expect($res->status())->toBe(200);

    $report = $res->json('data');
    expect($report['review_wait_ms']['known_count'])->toBe(1)
        ->and($report['review_wait_ms']['definition'])->toContain('wait')
        ->and($report['operation_observed_ms']['known_count'])->toBeGreaterThanOrEqual(1);
});

test('H10: technical attempts, prompt revisions, repairs, teacher changes, historical and current approvals count accurately', function () {
    $admin = EvidenceTestBuilder::createAdmin();
    $data = EvidenceTestBuilder::createCompleteCase();

    $from = now()->subHours(2)->toIso8601String();
    $to = now()->addHours(2)->toIso8601String();

    $res = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$from}&to={$to}");
    expect($res->status())->toBe(200);

    $report = $res->json('data');
    expect($report['current_approved_scene_count'])->toBe(1)
        ->and($report['historical_approval_count'])->toBe(1)
        ->and($report['prompt_revision_count'])->toBe(0)
        ->and($report['scene_repair_count'])->toBe(0)
        ->and($report['teacher_change_count'])->toBe(0);

    // Clear current approval pointer
    ReviewSectionState::where('case_id', $data['case']->id)->update(['current_approval_id' => null]);

    $res2 = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$from}&to={$to}");
    $report2 = $res2->json('data');
    expect($report2['current_approved_scene_count'])->toBe(0)
        ->and($report2['historical_approval_count'])->toBe(1);
});

test('H11 & H12: cost reporting separates known subtotals and marks full cost unknown when components are missing', function () {
    $admin = EvidenceTestBuilder::createAdmin();
    $data = EvidenceTestBuilder::createCompleteCase();

    $from = now()->subHours(2)->toIso8601String();
    $to = now()->addHours(2)->toIso8601String();

    $res = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$from}&to={$to}");
    expect($res->status())->toBe(200);

    $report = $res->json('data');
    $cost = $report['cost'];

    // In pilot, NotebookLM and review have no monetary charge feed, so full cost is unknown
    expect($cost['status'])->toBe('unknown')
        ->and($cost['unknown_component_count'])->toBeGreaterThan(0)
        ->and($cost['full_amount_minor'])->toBeNull()
        ->and($cost['per_current_approved_scene_minor'])->toBeNull()
        ->and($cost['per_scene_reason'])->toBe('unknown_components')
        ->and($cost['known_subtotals'])->not->toBeEmpty();

    // Check known subtotal includes coding attempt cost
    $usdSubtotal = collect($cost['known_subtotals'])->firstWhere('currency', 'USD');
    expect($usdSubtotal)->not->toBeNull()
        ->and($usdSubtotal['amount_minor'])->toBe(5); // 5 minor units from ReviewFixtures
});

test('H13: period membership [from,to) and boundary behavior are deterministic', function () {
    $admin = EvidenceTestBuilder::createAdmin();
    $data = EvidenceTestBuilder::createCompleteCase();

    // Query period in the distant future -> empty period
    $futureFrom = now()->addDays(5)->toIso8601String();
    $futureTo = now()->addDays(6)->toIso8601String();

    $res = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$futureFrom}&to={$futureTo}");
    expect($res->status())->toBe(200);

    $report = $res->json('data');
    expect($report['case_count'])->toBe(0)
        ->and($report['current_approved_scene_count'])->toBe(0)
        ->and($report['historical_approval_count'])->toBe(0)
        ->and($report['cost']['per_scene_reason'])->toBe('no_current_approvals')
        ->and($report['cost']['full_amount_minor'])->toBeNull();

    // Invalid period where from >= to
    $invalidRes = $this->actingAs($admin)->getJson("/v3/admin/evidence/metrics?from={$futureTo}&to={$futureFrom}");
    expect($invalidRes->status())->toBe(422)
        ->and($invalidRes->json('error.code'))->toBe('contract_invalid');
});
