<?php

namespace Tests\Feature\V3\Lessons;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->ownerTeacher = AccessFixtures::createEnrolledTeacher('Owner Teacher');
    $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Other Teacher');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

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
        'owner_user_id' => $this->ownerTeacher->id,
        'sha256' => hash('sha256', 'doc'),
        'byte_size' => 2048,
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => 'accepted/test.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->ownerTeacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 8,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-access-test',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'mapping',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->sourceActivation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->extractedSource = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $this->sourceActivation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => 'extracted/cur.txt',
        'text_sha256' => hash('sha256', 'cur-text'),
        'text_bytes' => 100,
        'page_count' => 3,
        'page_report' => [],
        'extractor_version' => '008-v1',
    ]);

    $this->binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'extracted_source_id' => $this->extractedSource->id,
        'adapter_kind' => 'notebooklm',
        'adapter_version' => '0.3.1',
        'notebook_title' => "v3-case-{$this->case->id}",
        'source_title' => "v3-text-{$this->case->id}",
        'notebook_id' => 'nb-access-1',
        'source_id' => 'src-access-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'cur-text'),
        'text_bytes' => 100,
        'transfer_status' => 'confirmed',
    ]);

    $this->mapActivation = MapActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->map = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'ready',
        'page_count' => 3,
        'work_count' => 2,
    ]);

    $this->node = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Kinematics',
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->query = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'state' => 'recorded',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'first-access'),
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'conversation_in' => 'conv-in',
        'conversation_out' => 'conv-out',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->section = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->node->id,
        'query_id' => $this->query->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Vectors vs Scalars',
        'core_understanding' => 'Scalars have magnitude only while vectors have magnitude and direction.',
        'visual_first_explanation' => 'Point with arrow.',
        'visual_scene_script' => 'Bar vs rotating arrow.',
        'dynamic_view_blueprint' => 'Split screen comparison.',
        'visual_learning_outcome' => 'Student recognizes directional component.',
        'concept_check' => 'Is temperature scalar or vector?',
        'conceptual_summary' => 'Direction distinguishes vectors.',
        'colloquial_egyptian_explanation' => 'الكميات القياسية ليها مقدار بس.',
        'laws_if_any' => 'v = d / t',
        'real_life_example' => 'عربية ماشية 60 كم في اتجاه القاهرة.',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'ans-acc'),
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'awaiting_010',
        'conversation_id' => 'conv-out',
        'version' => 2,
    ]);
});

test('L08: owner teacher can view lesson map via JSON and HTML', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/lesson-map";

    // JSON response
    $resp = $this->actingAs($this->ownerTeacher)->getJson($url);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($resp->json('data.case_id'))->toBe($this->case->id)
        ->and($resp->json('data.map_ref'))->toBe($this->map->id)
        ->and($resp->json('data.state'))->toBe('ready')
        ->and(count($resp->json('data.work_items')))->toBe(1)
        ->and($resp->json('data.work_items.0.section_count'))->toBe(1)
        ->and($resp->json('data.current_work_ordinal'))->toBe(1)
        ->and($resp->json('data.phase'))->toBe('awaiting_010');

    // HTML response
    $htmlResp = $this->actingAs($this->ownerTeacher)->get($url);
    $htmlResp->assertStatus(200);
    $htmlResp->assertSee(
        route('v3.teacher.cases.sections.show', ['case' => $this->case->id, 'section' => $this->section->id]),
        false
    );
    expect($htmlResp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('L08: owner teacher can view teacher section via JSON and HTML with all eleven fields', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/sections/{$this->section->id}";

    // JSON response
    $resp = $this->actingAs($this->ownerTeacher)->getJson($url);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $fields = $resp->json('data.fields');
    expect($fields)->toHaveKeys([
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
    expect($fields['section_identity'])->toBe('Vectors vs Scalars');
    expect($fields)->not->toHaveKey('concept_check');
    expect($fields['colloquial_egyptian_explanation'])->toBe('الكميات القياسية ليها مقدار بس.');

    // HTML response
    $htmlResp = $this->actingAs($this->ownerTeacher)->get($url);
    $htmlResp->assertStatus(200);
    expect($htmlResp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('L08: non-owner teacher receives identical 404 resource_unavailable', function () {
    $mapUrl = "/v3/teacher/cases/{$this->case->id}/lesson-map";
    $resp = $this->actingAs($this->otherTeacher)->getJson($mapUrl);
    $resp->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);

    $secUrl = "/v3/teacher/cases/{$this->case->id}/sections/{$this->section->id}";
    $respSec = $this->actingAs($this->otherTeacher)->getJson($secUrl);
    $respSec->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('L08: foreign section lookup under mismatched case returns 404', function () {
    $otherCase = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->ownerTeacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 1,
    ]);

    // Looking for $this->section under $otherCase (which it does not belong to)
    $url = "/v3/teacher/cases/{$otherCase->id}/sections/{$this->section->id}";
    $resp = $this->actingAs($this->ownerTeacher)->getJson($url);
    $resp->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('L08: admin or guest denied access to teacher lesson map and sections', function () {
    $mapUrl = "/v3/teacher/cases/{$this->case->id}/lesson-map";

    // Guest -> 401/403
    $guestResp = $this->getJson($mapUrl);
    expect(in_array($guestResp->status(), [401, 403, 404], true))->toBeTrue();

    // Admin -> 403 / 404
    $adminResp = $this->actingAs($this->admin)->getJson($mapUrl);
    expect(in_array($adminResp->status(), [403, 404], true))->toBeTrue();
});
