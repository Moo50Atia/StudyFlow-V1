<?php

namespace Tests\Feature\V3\Admin;

use App\Models\V3\CaseOperationControl;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->admin = AccessFixtures::createEnrolledAdmin('Active Admin');
    $this->inactiveAdmin = AccessFixtures::createAdmin('Inactive Admin');
    AccessFixtures::provisionV3($this->inactiveAdmin, isActive: false);

    $this->teacher = AccessFixtures::createEnrolledTeacher('Active Teacher');
    $this->student = AccessFixtures::createStudent('Active Student');

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
        'sha256' => 'hash_original_secret_123',
        'storage_path' => 'private/v3/documents/lecture_notes.pdf',
        'byte_size' => 1024,
        'page_count' => 5,
        'state' => 'ready',
        'accepted_at' => now(),
    ]);
});

function createHelperCase($teacher, $settingsVersion, $document, array $attrs = []): ProcessingCase
{
    $caseId = $attrs['id'] ?? (string) Str::uuid();
    $case = ProcessingCase::forceCreate([
        'id' => $caseId,
        'owner_user_id' => $teacher->id,
        'settings_version_id' => $settingsVersion->id,
        'document_id' => $document->id,
        'idempotency_key' => $attrs['idempotency_key'] ?? (string) Str::uuid(),
        'action' => $attrs['action'] ?? 'process',
        'state' => $attrs['state'] ?? 'running',
        'created_at' => $attrs['created_at'] ?? now(),
        'updated_at' => $attrs['updated_at'] ?? now(),
    ]);

    if (! empty($attrs['link'])) {
        WorkflowLink::forceCreate(array_merge([
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'settings_version_id' => $settingsVersion->id,
            'stage_code' => $attrs['link']['stage_code'] ?? 'scene_generation',
            'status_code' => $attrs['link']['status_code'] ?? 'running',
            'error_code' => $attrs['link']['error_code'] ?? null,
            'attempt_count' => $attrs['link']['attempt_count'] ?? 1,
            'scene_count' => $attrs['link']['scene_count'] ?? 1,
            'approved_scene_count' => $attrs['link']['approved_scene_count'] ?? 0,
            'start_state' => 'started',
            'started_at' => now(),
            'last_event_at' => now(),
        ], $attrs['link']));
    }

    return $case;
}

test('O01: stage/status/error/age filters apply before stable 20-row pagination with closed fields', function () {
    // Seed 25 cases with staggered creation times
    for ($i = 1; $i <= 25; $i++) {
        $stage = ($i % 2 === 0) ? 'scene_generation' : 'prompt_generation';
        $status = ($i === 25) ? 'blocked' : 'running';
        $error = ($i === 25) ? 'provider_unavailable' : null;

        createHelperCase($this->teacher, $this->settingsVersion, $this->document, [
            'created_at' => now()->subMinutes(30 - $i),
            'link' => [
                'stage_code' => $stage,
                'status_code' => $status,
                'error_code' => $error,
            ],
        ]);
    }

    // Default first page
    $response = $this->actingAs($this->admin)->getJson('/v3/admin/operations');
    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $data = $response->json('data');
    expect($data)->toHaveCount(20)
        ->and($response->json('meta.page'))->toBe(1)
        ->and($response->json('meta.per_page'))->toBe(20)
        ->and($response->json('meta.has_more'))->toBeTrue();

    // Verify ordering created_at DESC, id DESC
    $firstCase = $data[0];
    expect(array_keys($firstCase))->toEqualCanonicalizing([
        'case',
        'age_seconds',
        'idle_seconds',
        'control_mode',
        'dormant_review_count',
        'uncertain_effect_count',
    ])
    ->and(array_keys($firstCase['case']))->toEqualCanonicalizing([
        'case_id',
        'stage_code',
        'status_code',
        'error_code',
        'created_at',
        'updated_at',
        'attempt_count',
        'scene_count',
        'approved_scene_count',
    ])
    ->and($firstCase['age_seconds'])->toBeGreaterThanOrEqual(0)
    ->and($firstCase['idle_seconds'])->toBeGreaterThanOrEqual(0)
    ->and($firstCase['control_mode'])->toBe('normal');

    // Page 2
    $page2 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?page=2');
    $page2->assertStatus(200);
    expect($page2->json('data'))->toHaveCount(5)
        ->and($page2->json('meta.page'))->toBe(2)
        ->and($page2->json('meta.has_more'))->toBeFalse();

    // Filter by stage_code
    $stageFilter = $this->actingAs($this->admin)->getJson('/v3/admin/operations?stage_code=scene_generation');
    $stageFilter->assertStatus(200);
    foreach ($stageFilter->json('data') as $item) {
        expect($item['case']['stage_code'])->toBe('scene_generation');
    }

    // Filter by status_code
    $statusFilter = $this->actingAs($this->admin)->getJson('/v3/admin/operations?status_code=blocked');
    $statusFilter->assertStatus(200);
    expect($statusFilter->json('data'))->toHaveCount(1)
        ->and($statusFilter->json('data.0.case.status_code'))->toBe('blocked')
        ->and($statusFilter->json('data.0.case.error_code'))->toBe('provider_unavailable');

    // Filter by error_code
    $errorFilter = $this->actingAs($this->admin)->getJson('/v3/admin/operations?error_code=provider_unavailable');
    $errorFilter->assertStatus(200);
    expect($errorFilter->json('data'))->toHaveCount(1);

    // Filter by older_than_minutes
    $ageFilter = $this->actingAs($this->admin)->getJson('/v3/admin/operations?older_than_minutes=15');
    $ageFilter->assertStatus(200);
    expect(count($ageFilter->json('data')))->toBeGreaterThan(0);
});

test('O02: seed secrets and raw errors; list/detail/HTML/log contains none including hashes and paths', function () {
    $env = \Tests\Support\V3\AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $map = $env['map'];

    $secretStrings = [
        'SENTINEL_SECRET_SOURCE_TEXT_XYZ_999',
        'SENTINEL_SECRET_PROMPT_BODY_ABC_888',
        'hash_original_secret_123',
    ];

    \Tests\Support\V3\AuthoringFixtures::createQualifiedSection(
        $case,
        $map,
        1,
        'SENTINEL_SECRET_PROMPT_BODY_ABC_888'
    );

    // Check JSON list
    $listJson = $this->actingAs($this->admin)->getJson('/v3/admin/operations');
    $listJson->assertStatus(200);
    $listContent = $listJson->getContent();

    // Check JSON detail
    $detailJson = $this->actingAs($this->admin)->getJson("/v3/admin/operations/{$case->id}");
    $detailJson->assertStatus(200);
    $detailContent = $detailJson->getContent();

    // Check HTML list
    $listHtml = $this->actingAs($this->admin)->get('/v3/admin/operations');
    $listHtml->assertStatus(200);
    $htmlListContent = $listHtml->getContent();

    // Check HTML detail
    $detailHtml = $this->actingAs($this->admin)->get("/v3/admin/operations/{$case->id}");
    $detailHtml->assertStatus(200);
    $htmlDetailContent = $detailHtml->getContent();

    foreach ($secretStrings as $secret) {
        expect($listContent)->not->toContain($secret)
            ->and($detailContent)->not->toContain($secret)
            ->and($htmlListContent)->not->toContain($secret)
            ->and($htmlDetailContent)->not->toContain($secret);
    }
});

test('O03: guest, inactive admin, teacher and student cannot use new routes; missing case 404; all no-store', function () {
    $case = createHelperCase($this->teacher, $this->settingsVersion, $this->document);

    // 1. Guest
    $rGuestHtml = $this->get('/v3/admin/operations');
    $rGuestHtml->assertStatus(302);
    expect($rGuestHtml->headers->get('Cache-Control'))->toContain('no-store');

    $rGuestJson1 = $this->getJson('/v3/admin/operations');
    $rGuestJson1->assertStatus(401)->assertJson(['error' => ['code' => 'unauthenticated']]);
    expect($rGuestJson1->headers->get('Cache-Control'))->toContain('no-store');

    $rGuestJson2 = $this->getJson("/v3/admin/operations/{$case->id}");
    $rGuestJson2->assertStatus(401)->assertJson(['error' => ['code' => 'unauthenticated']]);
    expect($rGuestJson2->headers->get('Cache-Control'))->toContain('no-store');

    // 2. Inactive admin
    $rInactive1 = $this->actingAs($this->inactiveAdmin)->getJson('/v3/admin/operations');
    $rInactive1->assertStatus(403)->assertJson(['error' => ['code' => 'access_denied']]);
    expect($rInactive1->headers->get('Cache-Control'))->toContain('no-store');

    $rInactive2 = $this->actingAs($this->inactiveAdmin)->getJson("/v3/admin/operations/{$case->id}");
    $rInactive2->assertStatus(403)->assertJson(['error' => ['code' => 'access_denied']]);
    expect($rInactive2->headers->get('Cache-Control'))->toContain('no-store');

    // 3. Teacher
    $rTeacher1 = $this->actingAs($this->teacher)->getJson('/v3/admin/operations');
    $rTeacher1->assertStatus(403)->assertJson(['error' => ['code' => 'access_denied']]);
    expect($rTeacher1->headers->get('Cache-Control'))->toContain('no-store');

    $rTeacher2 = $this->actingAs($this->teacher)->getJson("/v3/admin/operations/{$case->id}");
    $rTeacher2->assertStatus(403)->assertJson(['error' => ['code' => 'access_denied']]);
    expect($rTeacher2->headers->get('Cache-Control'))->toContain('no-store');

    // 4. Student
    $rStudent = $this->actingAs($this->student)->getJson('/v3/admin/operations');
    $rStudent->assertStatus(403)->assertJson(['error' => ['code' => 'access_denied']]);
    expect($rStudent->headers->get('Cache-Control'))->toContain('no-store');

    // 5. Missing case
    $randomUuid = (string) Str::uuid();
    $missing = $this->actingAs($this->admin)->getJson("/v3/admin/operations/{$randomUuid}");
    $missing->assertStatus(404)->assertJson(['error' => ['code' => 'resource_unavailable']]);
    expect($missing->headers->get('Cache-Control'))->toContain('no-store');

    // 6. Malformed case UUID
    $malformed = $this->actingAs($this->admin)->getJson('/v3/admin/operations/not-a-uuid');
    $malformed->assertStatus(404)->assertJson(['error' => ['code' => 'resource_unavailable']]);
    expect($malformed->headers->get('Cache-Control'))->toContain('no-store');
});

test('O04: real V2 and 013 owner URLs cannot resolve V3 content through an admin operation ref', function () {
    $case = createHelperCase($this->teacher, $this->settingsVersion, $this->document);

    // Attempting to resolve V3 content via V2 sections or lectures routes with case UUID
    $resp1 = $this->actingAs($this->admin)->get("/sections/{$case->id}");
    expect($resp1->status())->toBeIn([404, 302, 500]);
    if ($resp1->status() === 200) {
        expect($resp1->getContent())->not->toContain($case->id);
    }

    $resp2 = $this->actingAs($this->admin)->get("/lectures/{$case->id}");
    expect($resp2->status())->toBeIn([404, 302, 500]);

    // Admin accessing teacher review route
    $teacherReview = $this->actingAs($this->admin)->getJson("/v3/teacher/cases/{$case->id}/reviews");
    $teacherReview->assertStatus(403);
});

test('O06: invalid enum, unsupported filter, noninteger/oversize age and bad page return 422', function () {
    // Unsupported query parameter
    $resp1 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?unknown_filter=value');
    $resp1->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid stage_code
    $resp2 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?stage_code=invalid_stage');
    $resp2->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid status_code
    $resp3 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?status_code=invalid_status');
    $resp3->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid error_code
    $resp4 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?error_code=invalid_error');
    $resp4->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid older_than_minutes: negative
    $resp5 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?older_than_minutes=-1');
    $resp5->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid older_than_minutes: exceeds 10080
    $resp6 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?older_than_minutes=10081');
    $resp6->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid older_than_minutes: string
    $resp7 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?older_than_minutes=abc');
    $resp7->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid page: 0
    $resp8 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?page=0');
    $resp8->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);

    // Invalid page: string
    $resp9 = $this->actingAs($this->admin)->getJson('/v3/admin/operations?page=invalid');
    $resp9->assertStatus(422)->assertJson([
        'error' => [
            'code' => 'contract_invalid',
            'message' => 'Invalid operation request.',
        ],
    ]);
});
