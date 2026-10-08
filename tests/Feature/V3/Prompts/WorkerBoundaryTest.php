<?php

namespace Tests\Feature\V3\Prompts;

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
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Worker Boundary');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Worker Boundary');

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

    $this->docContent = "PDF content";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-prompt-doc.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->docContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($this->docContent),
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => $this->docPath,
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
        'state_version' => 7,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-prompt-bnd',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'sections',
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
        'artifact_path' => 'extracted/prompt-ev.txt',
        'text_sha256' => hash('sha256', 'extracted text'),
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
        'notebook_id' => 'nb-pr-1',
        'source_id' => 'src-pr-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'extracted text'),
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
        'work_count' => 1,
    ]);

    $this->workNode = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Intro to Waves',
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->firstQuery = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'state' => 'recorded',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'first-req'),
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'conversation_in' => 'conv-in-1',
        'conversation_out' => 'conv-out-first-1',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->section = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode->id,
        'query_id' => $this->firstQuery->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Wave Propagation',
        'core_understanding' => 'Waves transfer energy without transferring matter.',
        'visual_first_explanation' => 'Visual particles oscillating in place.',
        'visual_scene_script' => 'Show a line of particles connected by springs.',
        'dynamic_view_blueprint' => 'Particles displaced in a wave pattern.',
        'visual_learning_outcome' => 'Learner understands particle motion vs wave motion.',
        'concept_check' => 'Do particles travel with the wave?',
        'conceptual_summary' => 'Energy travels while particles vibrate.',
        'colloquial_egyptian_explanation' => 'الموجة بتنقل الطاقة مش المادة.',
        'laws_if_any' => 'v = f * lambda',
        'real_life_example' => 'Stadium wave where people stand up and sit down.',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans'),
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'awaiting_010',
        'conversation_id' => 'conv-out-first-1',
        'version' => 2,
    ]);
});

test('P13: worker routes reject missing signature with 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-context/1";
    $this->getJson($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('P13: worker routes reject bad HMAC signature with 401 worker_unauthenticated before ID lookup', function () {
    $fakeCaseId = (string) Str::uuid();
    $path = "/api/internal/v1/cases/{$fakeCaseId}/prompt-context/1";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], 'invalid-secret');

    $this->withHeaders($headers)->get($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('P13: worker routes reject stale timestamp beyond 300 seconds with 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-context/1";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], null, time() - 305);

    $this->withHeaders($headers)->get($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('P13: web session or admin credentials cannot bypass worker HMAC signature', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-context/1";
    $this->actingAs($this->admin)->getJson($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('P13: foreign or unknown case_id returns 404 prompt_unavailable indistinguishable from missing', function () {
    $foreignId = (string) Str::uuid();
    $path = "/api/internal/v1/cases/{$foreignId}/prompt-context/1";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)->assertStatus(404)
        ->assertJson(['error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.']]);
});

test('P13: all responses include Cache-Control private no-store header', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-context/1";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});
