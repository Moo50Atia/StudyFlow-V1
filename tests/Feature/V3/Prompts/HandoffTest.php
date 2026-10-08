<?php

namespace Tests\Feature\V3\Prompts;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Handoff');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Handoff');

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
        'sha256' => hash('sha256', 'handoff doc'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-handoff.pdf',
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
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-handoff-1',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'prompt_qualification',
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
        'artifact_path' => 'extracted/handoff-ev.txt',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'page_count' => 2,
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
        'notebook_id' => 'nb-handoff-1',
        'source_id' => 'src-handoff-1',
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
        'page_count' => 2,
        'work_count' => 1,
    ]);

    $this->node = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => null,
        'kind' => 'lesson',
        'position_path' => '001',
        'title' => 'Lesson 1',
        'ordinal_in_parent' => 1,
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->firstQuery = \App\Models\V3\LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'request_sha256' => hash('sha256', 'first-req-1'),
        'state' => 'recorded',
        'send_count' => 1,
        'conversation_in' => 'conv-in-first-1',
        'conversation_out' => 'conv-out-first-1',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->section = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->node->id,
        'query_id' => $this->firstQuery->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Sec 1 Identity',
        'core_understanding' => 'Core 1',
        'visual_first_explanation' => 'Visual 1',
        'visual_scene_script' => 'Script 1',
        'dynamic_view_blueprint' => 'Blueprint 1',
        'visual_learning_outcome' => 'Outcome 1',
        'concept_check' => 'Check 1',
        'conceptual_summary' => 'Summary 1',
        'colloquial_egyptian_explanation' => 'Egyptian 1',
        'laws_if_any' => 'None',
        'real_life_example' => 'Example 1',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans'),
    ]);

    $this->workItem = PromptWorkItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->node->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 1,
        'section_query_id' => $this->firstQuery->id,
        'state' => 'completed',
        'section_refs' => [$this->section->id],
        'completion_ref' => 'prompt-work-comp-1',
        'completion_sha256' => hash('sha256', 'comp-1'),
    ]);

    $this->promptText = 'Process: Wave particles oscillating in place demonstrating transverse wave motion.';
    $this->promptSha = hash('sha256', $this->promptText);
    $this->promptPath = "prompt-versions/{$this->case->id}/{$this->promptSha}.txt";
    Storage::disk('v3_private')->put($this->promptPath, $this->promptText);

    $this->version1 = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->workItem->id,
        'section_id' => $this->section->id,
        'version_no' => 1,
        'origin' => 'third',
        'body_path' => $this->promptPath,
        'body_sha256' => $this->promptSha,
        'body_bytes' => strlen($this->promptText),
        'scene_type' => 'Process',
        'section_fields_sha256' => hash('sha256', 'fields'),
        'canonical_third_sha256' => hash('sha256', 'third'),
    ]);

    $this->attempt = PromptJevAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'prompt_version_id' => $this->version1->id,
        'settings_version_id' => $this->settingsVersion->id,
        'attempt_no' => 1,
        'state' => 'recorded',
        'requested_model' => 'typesafe-jev-v1',
        'request_sha256' => hash('sha256', 'jev-req-1'),
        'question_sha256' => hash('sha256', 'questions'),
    ]);

    $this->qualification = PromptQualification::forceCreate([
        'id' => (string) Str::uuid(),
        'prompt_version_id' => $this->version1->id,
        'winning_attempt_id' => $this->attempt->id,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'request_sha256' => hash('sha256', 'jev-req-1'),
        'answer_sha256' => hash('sha256', 'ans'),
        'question_sha256' => hash('sha256', 'questions'),
        'rule_version' => 'prompt-rule-v1',
        'requested_model' => 'typesafe-jev-v1',
        'returned_model' => 'typesafe-jev-v1',
        'choice' => 'pass',
        'choice_confidence' => '0.95',
        'score' => '90.0',
        'score_confidence' => '0.95',
        'noul_probability' => '0.95',
        'noul_yes' => true,
        'min_score' => 80,
        'min_confidence' => 80,
        'decision' => 'pass',
        'failed_checks' => [],
        'answer_path' => 'raw/jev.json',
        'created_at' => now(),
    ]);

    $this->sectionState = PromptSectionState::forceCreate([
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'current_prompt_version_id' => $this->version1->id,
        'status' => 'qualified',
        'last_qualification_id' => $this->qualification->id,
        'revision_count' => 0,
    ]);
});

test('P16: GET qualified-prompts returns exact private body, digest, and passing qualification for current pass', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/qualified-prompts/{$this->version1->id}";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('no-store');

    $data = $resp->json('data');
    expect($data['case_id'])->toBe($this->case->id);
    expect($data['section_ref'])->toBe($this->section->id);
    expect($data['prompt_version_ref'])->toBe($this->version1->id);
    expect($data['prompt'])->toBe($this->promptText);
    expect($data['body_sha256'])->toBe($this->promptSha);
    expect($data['qualification_ref'])->toBe($this->qualification->id);
    expect($data['settings_version_id'])->toBe($this->settingsVersion->id);
});

test('P16: GET qualified-prompts rejects unknown prompt version ref with 404 prompt_unavailable', function () {
    $fakeVersionId = (string) Str::uuid();
    $path = "/api/internal/v1/cases/{$this->case->id}/qualified-prompts/{$fakeVersionId}";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'prompt_unavailable',
                'message' => 'Prompt is unavailable.',
            ],
        ]);
});

test('P16: GET qualified-prompts rejects foreign case ID with 404 prompt_unavailable', function () {
    $otherCaseId = (string) Str::uuid();
    $path = "/api/internal/v1/cases/{$otherCaseId}/qualified-prompts/{$this->version1->id}";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'prompt_unavailable',
                'message' => 'Prompt is unavailable.',
            ],
        ]);
});

test('P16: GET qualified-prompts rejects superseded prompt version with 404 prompt_unavailable', function () {
    // Create successor version 2 that becomes current
    $v2Text = 'Process: Wave particles revised.';
    $v2Sha = hash('sha256', $v2Text);
    $v2Path = "prompt-versions/{$this->case->id}/{$v2Sha}.txt";
    Storage::disk('v3_private')->put($v2Path, $v2Text);

    $version2 = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->workItem->id,
        'section_id' => $this->section->id,
        'version_no' => 2,
        'predecessor_id' => $this->version1->id,
        'origin' => 'feedback',
        'body_path' => $v2Path,
        'body_sha256' => $v2Sha,
        'body_bytes' => strlen($v2Text),
        'scene_type' => 'Process',
        'section_fields_sha256' => hash('sha256', 'fields'),
        'canonical_third_sha256' => hash('sha256', 'third'),
    ]);

    // Update section state to point to version 2
    $this->sectionState->forceFill([
        'current_prompt_version_id' => $version2->id,
        'status' => 'awaiting_qualification',
    ])->save();

    // Version 1 is now superseded -> 404
    $path = "/api/internal/v1/cases/{$this->case->id}/qualified-prompts/{$this->version1->id}";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'prompt_unavailable',
                'message' => 'Prompt is unavailable.',
            ],
        ]);
});

test('P16: GET qualified-prompts rejects failed or blocked prompt version with 404 prompt_unavailable', function () {
    // Make section state blocked
    $this->sectionState->forceFill([
        'status' => 'blocked',
        'error_code' => 'policy_denied',
    ])->save();

    $path = "/api/internal/v1/cases/{$this->case->id}/qualified-prompts/{$this->version1->id}";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'prompt_unavailable',
                'message' => 'Prompt is unavailable.',
            ],
        ]);
});

test('P16: GET qualified-prompts denies unauthenticated request with 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/qualified-prompts/{$this->version1->id}";

    $resp = $this->get($path);
    $resp->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'worker_unauthenticated',
            ],
        ]);
});
