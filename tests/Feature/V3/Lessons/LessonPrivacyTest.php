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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Privacy Teacher');
    $this->admin = AccessFixtures::createEnrolledAdmin('Privacy Admin');

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
        'sha256' => hash('sha256', 'doc'),
        'byte_size' => 2048,
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => 'accepted/test.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
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
        'run_id' => 'run-priv-test',
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
        'notebook_id' => 'nb-priv-1',
        'source_id' => 'src-priv-1',
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
        'title' => 'Secret Lesson Title',
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
        'request_sha256' => hash('sha256', 'first-priv'),
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
        'section_identity' => 'Secret Educational Identity',
        'core_understanding' => 'Teacher-Only Concept',
        'visual_first_explanation' => 'Proprietary Visual Explanation',
        'visual_scene_script' => 'Secret Script',
        'dynamic_view_blueprint' => 'Secret Blueprint',
        'visual_learning_outcome' => 'Secret Outcome',
        'concept_check' => 'Secret Check',
        'conceptual_summary' => 'Secret Summary',
        'colloquial_egyptian_explanation' => 'شرح خاص بالمدرس فقط',
        'laws_if_any' => 'Secret Law',
        'real_life_example' => 'Secret Example',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'secret-answer'),
    ]);
});

test('L12: admin case summary DTO remains strictly closed without teacher educational content', function () {
    $url = "/v3/admin/cases/{$this->case->id}/summary";
    $resp = $this->actingAs($this->admin)->getJson($url);
    $resp->assertStatus(200);

    $raw = json_encode($resp->json());
    // Ensure no teacher section contents or titles leaked to admin
    expect($raw)->not->toContain('Secret Educational Identity')
        ->and($raw)->not->toContain('Teacher-Only Concept')
        ->and($raw)->not->toContain('Proprietary Visual Explanation')
        ->and($raw)->not->toContain('Secret Script')
        ->and($raw)->not->toContain('شرح خاص بالمدرس فقط')
        ->and($raw)->not->toContain('nb-priv-1')
        ->and($raw)->not->toContain('src-priv-1');
});

test('L12: V2 routes do not resolve V3 TeacherSection UUIDs', function () {
    // V2 routes like /sections/{id} or /lectures/{id} must not resolve V3 UUIDs
    $resp = $this->actingAs($this->teacher)->get("/sections/{$this->section->id}");
    expect(in_array($resp->status(), [404, 302, 403], true))->toBeTrue();
});
