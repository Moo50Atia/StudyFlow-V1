<?php

namespace Tests\Feature\V3\Admin;

use App\Models\User;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewReminder;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\V3AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\Support\V3\SettingsFixtures;

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
        'sha256' => hash('sha256', 'reminder doc'),
        'storage_path' => 'private/v3/documents/reminder.pdf',
        'byte_size' => 1024,
        'page_count' => 2,
        'state' => 'ready',
        'accepted_at' => now(),
    ]);
});

function createDormantReviewFixture(array $itemAttrs = []): array
{
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $teacher = $env['teacher'];

    $case->state = 'awaiting_review';
    $case->state_version = 2;
    $case->save();

    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $candidate = $qualified['candidate'];
    $section = $qualified['section'];

    $reviewItemId = (string) Str::uuid();
    $reviewItem = TeacherReviewItem::forceCreate(array_merge([
        'id' => $reviewItemId,
        'case_id' => $case->id,
        'section_id' => $section->id,
        'candidate_id' => $candidate->id,
        'qualification_id' => $qualified['qualification']->id,
        'prompt_version_id' => $candidate->prompt_version_id,
        'candidate_version' => 1,
        'status' => 'dormant',
        'state_version' => 1,
        'ready_at' => now()->subDays(3),
        'dormant_at' => now()->subDay(),
    ], $itemAttrs));

    $sectionState = ReviewSectionState::forceCreate([
        'case_id' => $case->id,
        'section_id' => $section->id,
        'current_review_item_id' => $reviewItemId,
        'current_approval_id' => null,
        'state' => 'dormant',
        'state_version' => 1,
    ]);

    return compact('case', 'section', 'candidate', 'reviewItem', 'sectionState', 'teacher');
}

test('O17: current dormant review item receives fixed in-app reminder visible only to original owner', function () {
    $fixture = createDormantReviewFixture();
    $case = $fixture['case'];
    $teacher = $fixture['teacher'];
    $reviewItem = $fixture['reviewItem'];

    $idemKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idemKey,
        'expected_review_version' => 1,
    ];

    $resp = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        $payload
    );

    $resp->assertStatus(201);
    $resp->assertHeader('Cache-Control', 'no-store, private');
    $reminderRef = $resp->json('data.reminder_ref');
    expect($resp->json('data.state'))->toBe('posted')
        ->and($reminderRef)->not->toBeNull();

    // Verify audit
    $audit = AdminOperationAudit::where('source_ref', $reminderRef)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('reminder_posted')
        ->and($audit->action_code)->toBe('remind')
        ->and($audit->actor_user_id)->toBe($this->admin->id);

    // Original teacher views HTML index -> sees the fixed reminder alert
    $htmlResp = $this->actingAs($teacher)->get("/v3/teacher/cases/{$case->id}/reviews");
    $htmlResp->assertStatus(200);
    $htmlResp->assertSee('A scene is ready for your review');
    $htmlResp->assertSee(route('v3.teacher.cases.reviews.show', [$case->id, $reviewItem->id]));

    // JSON review index shape remains unchanged (contract invariant)
    $jsonResp = $this->actingAs($teacher)->getJson("/v3/teacher/cases/{$case->id}/reviews");
    $jsonResp->assertStatus(200);
    $jsonResp->assertJsonStructure([
        'data' => [
            '*' => [
                'review_ref',
                'section_ref',
                'candidate_ref',
                'candidate_version',
                'status',
                'ready_at',
                'dormant_at',
            ],
        ],
        'meta' => ['page', 'per_page', 'total'],
    ]);
});

test('O18: repeat reminder key replays; second key and mismatched version return 409', function () {
    $fixture = createDormantReviewFixture();
    $case = $fixture['case'];
    $reviewItem = $fixture['reviewItem'];

    $idemKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idemKey,
        'expected_review_version' => 1,
    ];

    // 1. Initial post
    $resp1 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        $payload
    );
    $resp1->assertStatus(201);
    $reminderRef = $resp1->json('data.reminder_ref');

    // 2. Exact same key replay returns 200 with prior reminder
    $resp2 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        $payload
    );
    $resp2->assertStatus(200)
        ->assertJson([
            'data' => [
                'reminder_ref' => $reminderRef,
                'state' => 'posted',
            ],
        ]);

    // 3. Second key for same review item returns 409 operation_conflict
    $resp3 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
        ]
    );
    $resp3->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 4. Same key with changed payload returns 409 operation_conflict
    $resp4 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => $idemKey,
            'expected_review_version' => 2,
        ]
    );
    $resp4->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);
});

test('O18: reminder rejects non-dormant item, mismatched version, foreign or non-current review item', function () {
    $fixture = createDormantReviewFixture();
    $case = $fixture['case'];
    $reviewItem = $fixture['reviewItem'];

    // 1. Mismatched version on new key -> 409 operation_conflict
    $resp1 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 99,
        ]
    );
    $resp1->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 2. Non-dormant (pending) item -> 409 operation_conflict
    $pendingFixture = createDormantReviewFixture([
        'status' => 'pending',
    ]);
    $resp2 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$pendingFixture['case']->id}/reviews/{$pendingFixture['reviewItem']->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
        ]
    );
    $resp2->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 3. Foreign review item from another case -> 404 resource_unavailable
    $otherEnv = AuthoringFixtures::createAuthoringEnvironment();
    $otherCase = $otherEnv['case'];
    $resp3 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$otherCase->id}/reviews/{$reviewItem->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
        ]
    );
    $resp3->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);

    // 4. Non-current review item (section state does not point to it) -> 404 resource_unavailable
    $fixture['sectionState']->update(['current_review_item_id' => null]);
    $resp4 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
        ]
    );
    $resp4->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('O18 & O21: disabled teacher remains owner; sees reminder only after account restoration', function () {
    $fixture = createDormantReviewFixture();
    $case = $fixture['case'];
    $teacher = $fixture['teacher'];
    $reviewItem = $fixture['reviewItem'];

    // Disable the teacher
    V3AccountAccess::where('user_id', $teacher->id)->update(['is_active' => false]);
    $case->refresh();

    // Case owner remains unchanged
    expect($case->owner_user_id)->toBe($teacher->id);

    // Admin posts reminder successfully
    $resp = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/reviews/{$reviewItem->id}/reminders",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
        ]
    );
    $resp->assertStatus(201);

    // Disabled teacher cannot view review page (403)
    $denied = $this->actingAs($teacher)->get("/v3/teacher/cases/{$case->id}/reviews");
    $denied->assertStatus(403);

    // Restore teacher
    V3AccountAccess::where('user_id', $teacher->id)->update(['is_active' => true]);

    // Restored teacher now views reviews and sees the reminder alert
    $allowed = $this->actingAs($teacher)->get("/v3/teacher/cases/{$case->id}/reviews");
    $allowed->assertStatus(200);
    $allowed->assertSee('A scene is ready for your review');
});

test('O21: admin credentials cannot access teacher review routes', function () {
    $fixture = createDormantReviewFixture();
    $case = $fixture['case'];
    $reviewItem = $fixture['reviewItem'];

    // Admin cannot access teacher review index (403 denied by 004 teacher middleware)
    $resp1 = $this->actingAs($this->admin)->get("/v3/teacher/cases/{$case->id}/reviews");
    $resp1->assertStatus(403);

    // Admin cannot access teacher review show
    $resp2 = $this->actingAs($this->admin)->get("/v3/teacher/cases/{$case->id}/reviews/{$reviewItem->id}");
    $resp2->assertStatus(403);

    // Admin cannot access teacher review decision
    $resp3 = $this->actingAs($this->admin)->postJson("/v3/teacher/cases/{$case->id}/reviews/{$reviewItem->id}/decisions", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'decision' => 'approve',
    ]);
    $resp3->assertStatus(403);
});
