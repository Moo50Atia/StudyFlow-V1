<?php

namespace Tests\Feature\V3\Lessons;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Lessons');
    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $payload,
        'checksum' => hash('sha256', json_encode($payload)),
        'published_by_user_id' => $this->teacher->id,
        'published_at' => now(),
    ]);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'lesson test file'),
        'byte_size' => 4096,
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-lesson.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 2,
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
        'artifact_path' => 'extracted/test.txt',
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
        'notebook_id' => 'nb-test-1',
        'source_id' => 'src-test-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'transfer_status' => 'confirmed',
    ]);
});

test('exact seven v3 lesson tables and columns exist with required constraints', function () {
    expect(Schema::hasTable('v3_map_activations'))->toBeTrue();
    expect(Schema::hasTable('v3_lesson_maps'))->toBeTrue();
    expect(Schema::hasTable('v3_lesson_nodes'))->toBeTrue();
    expect(Schema::hasTable('v3_lesson_queries'))->toBeTrue();
    expect(Schema::hasTable('v3_teacher_sections'))->toBeTrue();
    expect(Schema::hasTable('v3_lesson_cursors'))->toBeTrue();
    expect(Schema::hasTable('v3_lesson_completions'))->toBeTrue();

    expect(Schema::hasColumns('v3_map_activations', [
        'id', 'case_id', 'binding_id', 'workflow_id', 'state', 'signal_attempts',
        'lease_until', 'signal_attempted_at', 'signal_accepted_at', 'next_check_at', 'last_alerted_at',
        'error_code', 'last_alert_code', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_lesson_maps', [
        'id', 'case_id', 'binding_id', 'activation_id', 'state',
        'divide_query_id', 'coverage_query_id',
        'divide_prompt_sha256', 'coverage_prompt_sha256', 'map_sha256',
        'page_count', 'work_count', 'error_code', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_lesson_nodes', [
        'id', 'map_id', 'parent_id', 'kind', 'position_path', 'ordinal_in_parent',
        'title', 'work_ordinal', 'coverage_pages', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_lesson_queries', [
        'id', 'case_id', 'map_id', 'kind', 'target_work_ordinal', 'state',
        'send_count', 'request_sha256', 'canonical_prompt_sha256',
        'conversation_in', 'conversation_out', 'answer_path', 'answer_sha256',
        'answer_bytes', 'sent_at', 'recorded_at', 'last_alerted_at',
        'error_code', 'last_alert_code', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_teacher_sections', [
        'id', 'case_id', 'map_id', 'work_node_id', 'query_id', 'ordinal_in_work',
        'section_identity', 'core_understanding', 'visual_first_explanation',
        'visual_scene_script', 'dynamic_view_blueprint', 'visual_learning_outcome',
        'concept_check', 'conceptual_summary', 'colloquial_egyptian_explanation',
        'laws_if_any', 'real_life_example', 'first_prompt_sha256', 'answer_sha256',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_lesson_cursors', [
        'case_id', 'map_id', 'current_work_ordinal', 'phase', 'conversation_id',
        'last_query_id', 'last_completion_id', 'error_code', 'version',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_lesson_completions', [
        'id', 'case_id', 'map_id', 'work_node_id', 'work_ordinal',
        'cursor_version',
        'section_refs', 'third_output_refs', 'completion_ref', 'completion_sha256',
        'final_conversation_id', 'signal_state', 'signal_attempts',
        'lease_until', 'signal_attempted_at', 'signal_accepted_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('MapActivation schema enforces uniqueness and foreign key restrict', function () {
    $actId = (string) Str::uuid();
    DB::table('v3_map_activations')->insert([
        'id' => $actId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Unique case_id constraint
    expect(function () {
        DB::table('v3_map_activations')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'binding_id' => $this->binding->id,
            'workflow_id' => "v3-case-{$this->case->id}",
            'state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);

    // FK restrict on case deletion
    expect(function () {
        $this->case->delete();
    })->toThrow(QueryException::class);
});

test('LessonNode schema enforces unique position_path and work_ordinal per map', function () {
    $actId = (string) Str::uuid();
    DB::table('v3_map_activations')->insert([
        'id' => $actId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mapId = (string) Str::uuid();
    DB::table('v3_lesson_maps')->insert([
        'id' => $mapId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $actId,
        'state' => 'ready',
        'page_count' => 3,
        'work_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $node1Id = (string) Str::uuid();
    DB::table('v3_lesson_nodes')->insert([
        'id' => $node1Id,
        'map_id' => $mapId,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'First Lesson',
        'work_ordinal' => 1,
        'coverage_pages' => json_encode([1, 2, 3]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate position_path within same map
    expect(function () use ($mapId) {
        DB::table('v3_lesson_nodes')->insert([
            'id' => (string) Str::uuid(),
            'map_id' => $mapId,
            'kind' => 'lesson',
            'position_path' => '001',
            'ordinal_in_parent' => 2,
            'title' => 'Duplicate Path Lesson',
            'work_ordinal' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);

    // Duplicate work_ordinal within same map
    expect(function () use ($mapId) {
        DB::table('v3_lesson_nodes')->insert([
            'id' => (string) Str::uuid(),
            'map_id' => $mapId,
            'kind' => 'lesson',
            'position_path' => '002',
            'ordinal_in_parent' => 2,
            'title' => 'Duplicate Work Ordinal Lesson',
            'work_ordinal' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('LessonQuery schema enforces unique (case_id, kind, target_work_ordinal)', function () {
    $actId = (string) Str::uuid();
    DB::table('v3_map_activations')->insert([
        'id' => $actId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mapId = (string) Str::uuid();
    DB::table('v3_lesson_maps')->insert([
        'id' => $mapId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $actId,
        'state' => 'pending',
        'page_count' => 3,
        'work_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('v3_lesson_queries')->insert([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $mapId,
        'kind' => 'divide',
        'target_work_ordinal' => 0,
        'state' => 'prepared',
        'send_count' => 0,
        'request_sha256' => hash('sha256', 'divide query'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate (case_id, kind, target_work_ordinal)
    expect(function () use ($mapId) {
        DB::table('v3_lesson_queries')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $mapId,
            'kind' => 'divide',
            'target_work_ordinal' => 0,
            'state' => 'prepared',
            'send_count' => 0,
            'request_sha256' => hash('sha256', 'divide query duplicate'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('TeacherSection schema requires all 11 fields and unique (work_node_id, ordinal_in_work)', function () {
    $actId = (string) Str::uuid();
    DB::table('v3_map_activations')->insert([
        'id' => $actId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mapId = (string) Str::uuid();
    DB::table('v3_lesson_maps')->insert([
        'id' => $mapId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $actId,
        'state' => 'ready',
        'page_count' => 3,
        'work_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $nodeId = (string) Str::uuid();
    DB::table('v3_lesson_nodes')->insert([
        'id' => $nodeId,
        'map_id' => $mapId,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Lesson 1',
        'work_ordinal' => 1,
        'coverage_pages' => json_encode([1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $queryId = (string) Str::uuid();
    DB::table('v3_lesson_queries')->insert([
        'id' => $queryId,
        'case_id' => $this->case->id,
        'map_id' => $mapId,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'state' => 'recorded',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'first query'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $section1Id = (string) Str::uuid();
    DB::table('v3_teacher_sections')->insert([
        'id' => $section1Id,
        'case_id' => $this->case->id,
        'map_id' => $mapId,
        'work_node_id' => $nodeId,
        'query_id' => $queryId,
        'ordinal_in_work' => 1,
        'section_identity' => 'Sec 1 Identity',
        'core_understanding' => 'Core Understanding',
        'visual_first_explanation' => 'Visual First',
        'visual_scene_script' => 'Scene Script',
        'dynamic_view_blueprint' => 'Dynamic Blueprint',
        'visual_learning_outcome' => 'Learning Outcome',
        'concept_check' => 'Concept Check',
        'conceptual_summary' => 'Conceptual Summary',
        'colloquial_egyptian_explanation' => 'Explanation in Egyptian',
        'laws_if_any' => '', // allowed empty
        'real_life_example' => 'Real life example',
        'first_prompt_sha256' => hash('sha256', 'first prompt'),
        'answer_sha256' => hash('sha256', 'answer text'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate ordinal_in_work under same work_node_id
    expect(function () use ($mapId, $nodeId, $queryId) {
        DB::table('v3_teacher_sections')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'query_id' => $queryId,
            'ordinal_in_work' => 1,
            'section_identity' => 'Sec 1 Duplicate',
            'core_understanding' => 'Core Understanding',
            'visual_first_explanation' => 'Visual First',
            'visual_scene_script' => 'Scene Script',
            'dynamic_view_blueprint' => 'Dynamic Blueprint',
            'visual_learning_outcome' => 'Learning Outcome',
            'concept_check' => 'Concept Check',
            'conceptual_summary' => 'Conceptual Summary',
            'colloquial_egyptian_explanation' => 'Explanation',
            'laws_if_any' => '',
            'real_life_example' => 'Example',
            'first_prompt_sha256' => hash('sha256', 'first prompt'),
            'answer_sha256' => hash('sha256', 'answer text'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('LessonCompletion schema enforces unique case work_ordinal and completion_ref', function () {
    $actId = (string) Str::uuid();
    DB::table('v3_map_activations')->insert([
        'id' => $actId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mapId = (string) Str::uuid();
    DB::table('v3_lesson_maps')->insert([
        'id' => $mapId,
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $actId,
        'state' => 'ready',
        'page_count' => 3,
        'work_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $nodeId = (string) Str::uuid();
    DB::table('v3_lesson_nodes')->insert([
        'id' => $nodeId,
        'map_id' => $mapId,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Lesson 1',
        'work_ordinal' => 1,
        'coverage_pages' => json_encode([1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $completionId = (string) Str::uuid();
    DB::table('v3_lesson_completions')->insert([
        'id' => $completionId,
        'case_id' => $this->case->id,
        'map_id' => $mapId,
        'work_node_id' => $nodeId,
        'work_ordinal' => 1,
        'section_refs' => json_encode([(string) Str::uuid()]),
        'third_output_refs' => json_encode(['third-ref-1']),
        'completion_ref' => 'comp-ref-1',
        'completion_sha256' => hash('sha256', 'completion hash'),
        'final_conversation_id' => 'conv-final-1',
        'signal_state' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate work_ordinal for same case
    expect(function () use ($mapId, $nodeId) {
        DB::table('v3_lesson_completions')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'work_ordinal' => 1,
            'section_refs' => json_encode([(string) Str::uuid()]),
            'third_output_refs' => json_encode(['third-ref-2']),
            'completion_ref' => 'comp-ref-2',
            'completion_sha256' => hash('sha256', 'completion hash 2'),
            'final_conversation_id' => 'conv-final-2',
            'signal_state' => 'committed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});
