<?php

namespace Tests\Feature\V3\Prompts;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptFeedbackEffect;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Services\V3\Prompts\PrivatePromptArtifactReader;
use App\Services\V3\Prompts\PromptOrphanCleaner;
use App\Services\V3\Prompts\PromptWarningLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    Cache::flush();

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Retention');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Retention');

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
        'sha256' => hash('sha256', 'retention doc'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-ret.pdf',
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
        'artifact_path' => 'extracted/ret-ev.txt',
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
        'notebook_id' => 'nb-ret-1',
        'source_id' => 'src-ret-1',
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
        'state' => 'awaiting_execution',
        'section_refs' => [$this->section->id],
    ]);
});

test('P17: PrivatePromptArtifactReader verifies digest and fails closed on mismatch or missing', function () {
    $disk = Storage::disk('v3_private');
    $validContent = 'Process: valid scene prompt body';
    $validSha = hash('sha256', $validContent);
    $path = "prompt-versions/{$this->case->id}/{$validSha}.txt";

    $disk->put($path, $validContent);

    $reader = app(PrivatePromptArtifactReader::class);

    // Matching sha succeeds
    $content = $reader->read($path, $validSha);
    expect($content)->toBe($validContent);

    // Corrupted content throws RuntimeException and fails closed
    $disk->put($path, 'Corrupted modified content');
    expect(fn () => $reader->read($path, $validSha))
        ->toThrow(\RuntimeException::class);

    // Missing file throws RuntimeException
    expect(fn () => $reader->read("prompt-versions/{$this->case->id}/nonexistent.txt", $validSha))
        ->toThrow(\RuntimeException::class);
});

test('P17: PromptOrphanCleaner never deletes referenced or uncertain files, purges unreferenced >24h', function () {
    $disk = Storage::disk('v3_private');
    $now = time();
    $twoDaysAgo = $now - (48 * 3600);
    $oneHourAgo = $now - 3600;

    // 1. Referenced prompt version file older than 24h -> must NOT be purged
    $refSha = hash('sha256', 'Process: referenced version');
    $refPath = "prompt-versions/{$this->case->id}/{$refSha}.txt";
    $disk->put($refPath, 'Process: referenced version');
    touch(Storage::disk('v3_private')->path($refPath), $twoDaysAgo);

    ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->workItem->id,
        'section_id' => $this->section->id,
        'version_no' => 1,
        'origin' => 'third',
        'body_path' => $refPath,
        'body_sha256' => $refSha,
        'body_bytes' => strlen('Process: referenced version'),
        'scene_type' => 'Process',
        'section_fields_sha256' => hash('sha256', 'fields'),
        'canonical_third_sha256' => hash('sha256', 'third'),
    ]);

    // 2. Uncertain turn file older than 24h -> must NEVER be purged
    $uncertainSha = hash('sha256', 'uncertain turn body');
    $uncertainPath = "prompt-turns/{$this->case->id}/{$uncertainSha}.txt";
    $disk->put($uncertainPath, 'uncertain turn body');
    touch(Storage::disk('v3_private')->path($uncertainPath), $twoDaysAgo);

    PromptTurn::forceCreate([
        'id' => (string) Str::uuid(),
        'work_item_id' => $this->workItem->id,
        'step' => 'execution',
        'origin' => 'sent',
        'state' => 'uncertain',
        'request_sha256' => hash('sha256', 'req'),
        'canonical_sha256' => hash('sha256', 'can'),
        'conversation_in' => 'conv-in',
        'answer_path' => $uncertainPath,
        'answer_sha256' => $uncertainSha,
        'error_code' => 'timeout',
    ]);

    // 3. Unreferenced file younger than 24h -> must NOT be purged
    $youngPath = "prompt-turns/{$this->case->id}/young_unreferenced.txt";
    $disk->put($youngPath, 'young content');
    touch(Storage::disk('v3_private')->path($youngPath), $oneHourAgo);

    // 4. Unreferenced orphan file older than 24h -> MUST be purged
    $orphanPath = "prompt-turns/{$this->case->id}/orphan_old.txt";
    $disk->put($orphanPath, 'orphan content');
    touch(Storage::disk('v3_private')->path($orphanPath), $twoDaysAgo);

    $cleaner = app(PromptOrphanCleaner::class);
    $purged = $cleaner->purgeOrphans(86400);

    expect($purged)->toBe(1);
    expect($disk->exists($refPath))->toBeTrue();
    expect($disk->exists($uncertainPath))->toBeTrue();
    expect($disk->exists($youngPath))->toBeTrue();
    expect($disk->exists($orphanPath))->toBeFalse();
});

test('P17: PromptWarningLogger rate limits repeat warnings within 24h and includes IDs only', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('prompt_artifact_missing', [
            'code' => 'prompt_artifact_missing',
            'case_id' => $this->case->id,
            'artifact_path' => 'prompt-versions/test.txt',
        ]);

    PromptWarningLogger::warnOnce('prompt_artifact_missing', [
        'code' => 'prompt_artifact_missing',
        'case_id' => $this->case->id,
        'artifact_path' => 'prompt-versions/test.txt',
    ]);

    // Second call within 24 hours should be suppressed (no additional Log::warning call)
    PromptWarningLogger::warnOnce('prompt_artifact_missing', [
        'code' => 'prompt_artifact_missing',
        'case_id' => $this->case->id,
        'artifact_path' => 'prompt-versions/test.txt',
    ]);
});
