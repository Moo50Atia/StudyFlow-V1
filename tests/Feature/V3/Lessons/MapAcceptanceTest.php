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
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Map Acceptance');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Map Acceptance');

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
    $this->docPath = 'accepted/test-map-acc.pdf';
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
        'run_id' => 'run-map-acc',
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
        'artifact_path' => 'extracted/map-acc.txt',
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
        'notebook_id' => 'nb-acc-1',
        'source_id' => 'src-acc-1',
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
});

test('L05: Divide result acceptance creates pending map and hierarchical nodes atomically', function () {
    // 1. Claim divide query
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $claimPayload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => 'First, I want you to act as a Data Architect...',
        'conversation_in' => null,
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $claimResp->assertStatus(200);
    $queryRef = $claimResp->json('data.query_ref');

    // 2. Post divide result
    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/result";
    $resultPayload = [
        'schema_version' => 1,
        'answer' => "Chapter 1: Kinematics\nLesson 1.1: Motion\nLesson 1.2: Velocity",
        'conversation_out' => 'conv-divide-1',
        'parsed' => [
            'nodes' => [
                [
                    'kind' => 'chapter',
                    'position_path' => '001',
                    'parent_position_path' => null,
                    'title' => 'Kinematics',
                    'ordinal_in_parent' => 1,
                    'work_ordinal' => null,
                ],
                [
                    'kind' => 'lesson',
                    'position_path' => '001.001',
                    'parent_position_path' => '001',
                    'title' => 'Motion',
                    'ordinal_in_parent' => 1,
                    'work_ordinal' => 1,
                ],
                [
                    'kind' => 'lesson',
                    'position_path' => '001.002',
                    'parent_position_path' => '001',
                    'title' => 'Velocity',
                    'ordinal_in_parent' => 2,
                    'work_ordinal' => 2,
                ],
            ],
        ],
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $resultResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);

    $resultResp->assertStatus(201);
    expect($resultResp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $mapRef = $resultResp->json('data.map_ref');
    expect($mapRef)->not->toBeNull();

    // Verify map in database
    $map = LessonMap::find($mapRef);
    expect($map)->not->toBeNull();
    expect($map->state)->toBe('pending');
    expect($map->work_count)->toBe(2);
    expect($map->page_count)->toBe(3);

    // Verify nodes created
    $nodes = LessonNode::where('map_id', $mapRef)->get();
    expect($nodes)->toHaveCount(3);

    // Verify raw answer written to disk
    $answerSha = hash('sha256', $resultPayload['answer']);
    $expectedPath = "lesson-answers/{$this->case->id}/{$queryRef}/{$answerSha}.txt";
    expect(Storage::disk('v3_private')->exists($expectedPath))->toBeTrue();
});

test('L05: Coverage result acceptance transitions map to ready and creates cursor', function () {
    // 1. Setup pending map with nodes
    $map = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'pending',
        'page_count' => 3,
        'work_count' => 2,
    ]);

    $ch = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $map->id,
        'kind' => 'chapter',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Kinematics',
    ]);

    $w1 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $map->id,
        'parent_id' => $ch->id,
        'kind' => 'lesson',
        'position_path' => '001.001',
        'ordinal_in_parent' => 1,
        'title' => 'Motion',
        'work_ordinal' => 1,
    ]);

    $w2 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $map->id,
        'parent_id' => $ch->id,
        'kind' => 'lesson',
        'position_path' => '001.002',
        'ordinal_in_parent' => 2,
        'title' => 'Velocity',
        'work_ordinal' => 2,
    ]);

    // 2. Claim coverage query
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/coverage/0/claim";
    $claimPayload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => 'COVERAGE CHECK v1...',
        'conversation_in' => 'conv-divide-1',
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $queryRef = $claimResp->json('data.query_ref');

    // 3. Post coverage result
    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/result";
    $resultPayload = [
        'schema_version' => 1,
        'answer' => "PAGE 1: W1\nPAGE 2: W1, W2\nPAGE 3: W2",
        'conversation_out' => 'conv-coverage-1',
        'parsed' => [
            'pages' => [
                ['page' => 1, 'work_ordinals' => [1]],
                ['page' => 2, 'work_ordinals' => [1, 2]],
                ['page' => 3, 'work_ordinals' => [2]],
            ],
        ],
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $resultResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);

    $resultResp->assertStatus(201);

    // Verify map is now ready
    $map->refresh();
    expect($map->state)->toBe('ready');
    expect($map->coverage_query_id)->toBe($queryRef);

    // Verify work nodes have coverage_pages
    $w1->refresh();
    $w2->refresh();
    expect($w1->coverage_pages)->toBe([1, 2]);
    expect($w2->coverage_pages)->toBe([2, 3]);

    // Verify cursor is created
    $cursor = LessonCursor::where('case_id', $this->case->id)->first();
    expect($cursor)->not->toBeNull();
    expect($cursor->map_id)->toBe($map->id);
    expect($cursor->current_work_ordinal)->toBe(1);
    expect($cursor->phase)->toBe('first_pending');
    expect($cursor->conversation_id)->toBe('conv-coverage-1');
    expect($cursor->version)->toBe(1);
});

test('L05: Duplicate exact result replay is idempotent and returns 200', function () {
    $map = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'pending',
        'page_count' => 3,
        'work_count' => 1,
    ]);

    $query = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $map->id,
        'kind' => 'divide',
        'target_work_ordinal' => 0,
        'state' => 'sending',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'req'),
    ]);

    $resultPath = "/api/internal/v1/lesson-queries/{$query->id}/result";
    $resultPayload = [
        'schema_version' => 1,
        'answer' => "Chapter 1: Kinematics\nLesson 1.1: Motion",
        'conversation_out' => 'conv-1',
        'parsed' => [
            'nodes' => [
                [
                    'kind' => 'chapter',
                    'position_path' => '001',
                    'parent_position_path' => null,
                    'title' => 'Kinematics',
                    'ordinal_in_parent' => 1,
                    'work_ordinal' => null,
                ],
                [
                    'kind' => 'lesson',
                    'position_path' => '001.001',
                    'parent_position_path' => '001',
                    'title' => 'Motion',
                    'ordinal_in_parent' => 1,
                    'work_ordinal' => 1,
                ],
            ],
        ],
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);

    // First post: 201
    $resp1 = $this->withHeaders($headers)->postJson($resultPath, $resultPayload);
    $resp1->assertStatus(201);

    // Second post with identical body: 200
    $resp2 = $this->withHeaders($headers)->postJson($resultPath, $resultPayload);
    $resp2->assertStatus(200);
});

test('L05: Conflicting result for recorded query returns 409 lesson_conflict', function () {
    $map = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'pending',
        'page_count' => 3,
        'work_count' => 1,
    ]);

    $query = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $map->id,
        'kind' => 'divide',
        'target_work_ordinal' => 0,
        'state' => 'sending',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'req'),
    ]);

    $resultPath = "/api/internal/v1/lesson-queries/{$query->id}/result";
    $resultPayload = [
        'schema_version' => 1,
        'answer' => "Chapter 1: Kinematics\nLesson 1.1: Motion",
        'conversation_out' => 'conv-1',
        'parsed' => [
            'nodes' => [
                [
                    'kind' => 'chapter',
                    'position_path' => '001',
                    'parent_position_path' => null,
                    'title' => 'Kinematics',
                    'ordinal_in_parent' => 1,
                    'work_ordinal' => null,
                ],
                [
                    'kind' => 'lesson',
                    'position_path' => '001.001',
                    'parent_position_path' => '001',
                    'title' => 'Motion',
                    'ordinal_in_parent' => 1,
                    'work_ordinal' => 1,
                ],
            ],
        ],
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $this->withHeaders($headers)->postJson($resultPath, $resultPayload)->assertStatus(201);

    // Now try posting a different answer to same query
    $differentPayload = $resultPayload;
    $differentPayload['answer'] = 'Different answer';
    $diffHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $differentPayload);
    $this->withHeaders($diffHeaders)->postJson($resultPath, $differentPayload)->assertStatus(409)
        ->assertJson(['error' => ['code' => 'lesson_conflict']]);
});

test('L05: signed context projections expose committed map paths, conversation, cursor and Sections', function () {
    Config::set('services.v3.callback_hmac_key', 'test-callback-hmac-key');
    $map = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'ready',
        'page_count' => 3,
        'work_count' => 1,
    ]);
    $chapter = LessonNode::forceCreate([
        'id' => (string) Str::uuid(), 'map_id' => $map->id, 'kind' => 'chapter',
        'position_path' => '001', 'ordinal_in_parent' => 1, 'title' => 'Kinematics',
    ]);
    $work = LessonNode::forceCreate([
        'id' => (string) Str::uuid(), 'map_id' => $map->id, 'parent_id' => $chapter->id,
        'kind' => 'lesson', 'position_path' => '001.001', 'ordinal_in_parent' => 1,
        'title' => 'Motion', 'work_ordinal' => 1,
    ]);
    $query = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(), 'case_id' => $this->case->id, 'map_id' => $map->id,
        'kind' => 'divide', 'target_work_ordinal' => 0, 'state' => 'recorded',
        'send_count' => 1, 'request_sha256' => hash('sha256', 'divide-request'),
        'answer_sha256' => hash('sha256', 'divide-answer'), 'conversation_out' => 'divide-conv-1',
    ]);
    $map->update(['divide_query_id' => $query->id]);
    $firstQuery = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(), 'case_id' => $this->case->id, 'map_id' => $map->id,
        'kind' => 'first', 'target_work_ordinal' => 1, 'state' => 'recorded',
        'send_count' => 1, 'request_sha256' => hash('sha256', 'first-request'),
        'answer_sha256' => hash('sha256', 'first-answer'),
    ]);
    $section = \App\Models\V3\TeacherSection::forceCreate([
        'id' => (string) Str::uuid(), 'case_id' => $this->case->id, 'map_id' => $map->id,
        'work_node_id' => $work->id, 'query_id' => $firstQuery->id, 'ordinal_in_work' => 1,
        'section_identity' => 'Section identity', 'core_understanding' => 'Core understanding',
        'visual_first_explanation' => 'Visual explanation', 'visual_scene_script' => 'Scene script',
        'dynamic_view_blueprint' => 'Blueprint', 'visual_learning_outcome' => 'Outcome',
        'concept_check' => 'Question', 'conceptual_summary' => 'Summary',
        'colloquial_egyptian_explanation' => 'Explanation', 'laws_if_any' => 'Laws',
        'real_life_example' => 'Example', 'first_prompt_sha256' => hash('sha256', 'prompt'),
        'answer_sha256' => hash('sha256', 'answer'),
    ]);
    LessonCursor::forceCreate([
        'case_id' => $this->case->id, 'map_id' => $map->id, 'current_work_ordinal' => 1,
        'phase' => 'first_pending', 'conversation_id' => 'lesson-conv-1', 'version' => 4,
    ]);

    $mapPath = "/api/internal/v1/map-activations/{$this->mapActivation->id}/context";
    $mapResponse = $this->withHeaders(WorkflowFixtures::signCallbackRequest('GET', $mapPath, []))
        ->get($mapPath);
    $mapResponse->assertOk()->assertJsonPath('data.map_state', 'ready')
        ->assertJsonPath('data.map_ref', $map->id)->assertJsonPath('data.work_count', 1)
        ->assertJsonPath('data.work_items.0.work_path', 'Kinematics / Motion')
        ->assertJsonPath('data.divide_conversation_ref', 'divide-conv-1');

    $lessonPath = "/api/internal/v1/cases/{$this->case->id}/lesson-context/1";
    $lessonResponse = $this->withHeaders(WorkflowFixtures::signCallbackRequest('GET', $lessonPath, []))
        ->get($lessonPath);
    $lessonResponse->assertOk()->assertJsonPath('data.work_path', 'Kinematics / Motion')
        ->assertJsonPath('data.cursor_version', 4)->assertJsonPath('data.section_refs.0', $section->id);
});
