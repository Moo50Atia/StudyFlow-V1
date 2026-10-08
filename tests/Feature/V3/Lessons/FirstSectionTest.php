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
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher First Section');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin First Section');

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
        'state_version' => 7,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-first-sec',
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
        'artifact_path' => 'extracted/first.txt',
        'text_sha256' => hash('sha256', 'text'),
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
        'notebook_id' => 'nb-first-1',
        'source_id' => 'src-first-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'text'),
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

    $this->chapter = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'kind' => 'chapter',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Kinematics',
    ]);

    $this->workNode1 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => $this->chapter->id,
        'kind' => 'lesson',
        'position_path' => '001.001',
        'ordinal_in_parent' => 1,
        'title' => 'Motion',
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->workNode2 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => $this->chapter->id,
        'kind' => 'lesson',
        'position_path' => '001.002',
        'ordinal_in_parent' => 2,
        'title' => 'Velocity',
        'work_ordinal' => 2,
        'coverage_pages' => [2, 3],
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'first_pending',
        'conversation_id' => 'conv-map-1',
        'version' => 1,
    ]);

    $this->query = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'state' => 'sending',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'first-req-1'),
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'conversation_in' => 'conv-map-1',
        'sent_at' => now(),
    ]);
});

test('L06: First Sections acceptance commits all eleven fields and moves cursor to awaiting_010', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->query->id}/result";
    $payload = [
        'schema_version' => 1,
        'answer' => "Lecture: W1 | Kinematics / Motion\n\nSection 1:\n...",
        'conversation_out' => 'conv-first-out-1',
        'parsed' => [
            'work_ordinal' => 1,
            'lecture_header' => 'Lecture: W1 | Kinematics / Motion',
            'sections' => [
                [
                    'ordinal' => 1,
                    'fields' => [
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
                    ],
                ],
                [
                    'ordinal' => 2,
                    'fields' => [
                        'section_identity' => 'Vector Addition',
                        'core_understanding' => 'Vectors add tip to tail.',
                        'visual_first_explanation' => 'Two arrows chained.',
                        'visual_scene_script' => 'Move second vector to tip.',
                        'dynamic_view_blueprint' => 'Interactive canvas.',
                        'visual_learning_outcome' => 'Geometric addition visualization.',
                        'concept_check' => 'Resultant at 180 degrees?',
                        'conceptual_summary' => 'Resultant vector represents total.',
                        'colloquial_egyptian_explanation' => 'ديل التاني على راس الأولاني.',
                        'laws_if_any' => 'R = A + B',
                        'real_life_example' => 'اتنين بيشدوا صندوق بحبلين.',
                    ],
                ],
            ],
            'completion_marker' => null,
        ],
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(201);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    // Verify sections created in DB
    $sections = TeacherSection::where('work_node_id', $this->workNode1->id)
        ->orderBy('ordinal_in_work')
        ->get();
    expect($sections)->toHaveCount(2);

    expect($sections[0]->section_identity)->toBe('Vectors vs Scalars');
    expect($sections[0]->core_understanding)->toBe('Scalars have magnitude only while vectors have magnitude and direction.');
    expect($sections[0]->colloquial_egyptian_explanation)->toBe('الكميات القياسية ليها مقدار بس.');
    expect($sections[0]->laws_if_any)->toBe('v = d / t');
    expect($sections[0]->first_prompt_sha256)->toBe(\App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256);

    // Verify cursor updated to awaiting_010
    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('awaiting_010');
    expect($this->cursor->last_query_id)->toBe($this->query->id);

    // Verify raw answer written to storage
    $answerSha = hash('sha256', $payload['answer']);
    $expectedPath = "lesson-answers/{$this->case->id}/{$this->query->id}/{$answerSha}.txt";
    expect(Storage::disk('v3_private')->exists($expectedPath))->toBeTrue();
});

test('L07: First Sections acceptance allows explicitly empty laws_if_any', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->query->id}/result";
    $payload = [
        'schema_version' => 1,
        'answer' => "Lecture: W1 | Kinematics / Motion\n\nSection 1:\n...",
        'conversation_out' => 'conv-first-out-1',
        'parsed' => [
            'work_ordinal' => 1,
            'lecture_header' => 'Lecture: W1 | Kinematics / Motion',
            'sections' => [
                [
                    'ordinal' => 1,
                    'fields' => [
                        'section_identity' => 'Conceptual Basis',
                        'core_understanding' => 'Base concepts without equations.',
                        'visual_first_explanation' => 'Visual.',
                        'visual_scene_script' => 'Scene.',
                        'dynamic_view_blueprint' => 'Blueprint.',
                        'visual_learning_outcome' => 'Outcome.',
                        'concept_check' => 'Check?',
                        'conceptual_summary' => 'Summary.',
                        'colloquial_egyptian_explanation' => 'شرح عامي.',
                        'laws_if_any' => '',
                        'real_life_example' => 'مثال واقعي.',
                    ],
                ],
            ],
            'completion_marker' => null,
        ],
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(201);
    $sec = TeacherSection::where('work_node_id', $this->workNode1->id)->first();
    expect($sec)->not->toBeNull();
    expect($sec->laws_if_any)->toBe('');
});

test('L07: First Sections acceptance rejects missing required field with 422', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->query->id}/result";
    $payload = [
        'schema_version' => 1,
        'answer' => "Incomplete",
        'conversation_out' => 'conv-first-out-1',
        'parsed' => [
            'work_ordinal' => 1,
            'lecture_header' => 'Lecture: W1 | Kinematics / Motion',
            'sections' => [
                [
                    'ordinal' => 1,
                    'fields' => [
                        'section_identity' => 'Missing fields',
                        'core_understanding' => 'Only two fields present',
                    ],
                ],
            ],
            'completion_marker' => null,
        ],
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(422)
        ->assertJson(['error' => ['code' => 'lesson_invalid']]);

    // Cursor must NOT have advanced
    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('first_pending');
});

test('L07: First Sections acceptance rejects empty required field with 422', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->query->id}/result";
    $payload = [
        'schema_version' => 1,
        'answer' => "Empty required field",
        'conversation_out' => 'conv-first-out-1',
        'parsed' => [
            'work_ordinal' => 1,
            'lecture_header' => 'Lecture: W1 | Kinematics / Motion',
            'sections' => [
                [
                    'ordinal' => 1,
                    'fields' => [
                        'section_identity' => 'Empty Core',
                        'core_understanding' => '   ', // whitespace only
                        'visual_first_explanation' => 'Visual.',
                        'visual_scene_script' => 'Scene.',
                        'dynamic_view_blueprint' => 'Blueprint.',
                        'visual_learning_outcome' => 'Outcome.',
                        'concept_check' => 'Check?',
                        'conceptual_summary' => 'Summary.',
                        'colloquial_egyptian_explanation' => 'شرح عامي.',
                        'laws_if_any' => '',
                        'real_life_example' => 'مثال واقعي.',
                    ],
                ],
            ],
            'completion_marker' => null,
        ],
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(422)
        ->assertJson(['error' => ['code' => 'lesson_invalid']]);
});

test('L07: First Sections acceptance rejects mismatched work ordinal with 422', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->query->id}/result";
    $payload = [
        'schema_version' => 1,
        'answer' => "Wrong work ordinal",
        'conversation_out' => 'conv-first-out-1',
        'parsed' => [
            'work_ordinal' => 99, // Mismatched from target_work_ordinal 1
            'lecture_header' => 'Lecture: W99 | ...',
            'sections' => [],
            'completion_marker' => null,
        ],
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(422)
        ->assertJson(['error' => ['code' => 'lesson_invalid']]);
});

test('L06: First Sections acceptance is idempotent on exact same answer replay', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->query->id}/result";
    $payload = [
        'schema_version' => 1,
        'answer' => "Lecture: W1 | Kinematics\n\nSection 1:\n...",
        'conversation_out' => 'conv-first-out-1',
        'parsed' => [
            'work_ordinal' => 1,
            'lecture_header' => 'Lecture: W1 | Kinematics',
            'sections' => [
                [
                    'ordinal' => 1,
                    'fields' => [
                        'section_identity' => 'Identity',
                        'core_understanding' => 'Core',
                        'visual_first_explanation' => 'Visual.',
                        'visual_scene_script' => 'Scene.',
                        'dynamic_view_blueprint' => 'Blueprint.',
                        'visual_learning_outcome' => 'Outcome.',
                        'concept_check' => 'Check?',
                        'conceptual_summary' => 'Summary.',
                        'colloquial_egyptian_explanation' => 'شرح عامي.',
                        'laws_if_any' => '',
                        'real_life_example' => 'مثال.',
                    ],
                ],
            ],
            'completion_marker' => null,
        ],
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp1 = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);
    $resp1->assertStatus(201);

    // Exact replay returns 200
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp2 = $this->postJson($path, $payload, $headers2, JSON_UNESCAPED_SLASHES);
    $resp2->assertStatus(200);

    // Changed answer returns 409
    $payloadConflict = $payload;
    $payloadConflict['answer'] = "Different answer content";
    $headersConflict = WorkflowFixtures::signCallbackRequest('POST', $path, $payloadConflict);
    $respConflict = $this->postJson($path, $payloadConflict, $headersConflict, JSON_UNESCAPED_SLASHES);
    $respConflict->assertStatus(409)
        ->assertJson(['error' => ['code' => 'lesson_conflict']]);
});

