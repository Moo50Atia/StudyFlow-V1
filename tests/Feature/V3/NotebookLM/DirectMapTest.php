<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

test('N10: Direct map parser accepts NotebookLM arrow-prefixed lesson children', function () {
    $nodes = \App\Services\V3\NotebookLM\DirectMapParser::parse(
        "Chapter 1: Fractions [1]\n=> Lesson 1: Equivalent Fractions [1]\n"
    );

    expect($nodes)->toHaveCount(2)
        ->and($nodes[1]['kind'])->toBe('lesson')
        ->and($nodes[1]['parent_position_path'])->toBe('001')
        ->and($nodes[1]['work_ordinal'])->toBe(1);
});

test('direct map parser accepts NotebookLM Unicode tree hierarchies and repeated guides', function () {
    $nodes = \App\Services\V3\NotebookLM\DirectMapParser::parse(
        "Chapter: Unit 3: Geometry\n" .
        "├── Mini Chapter: Similarity\n" .
        "│   ├── Lesson 1: Similar Polygons\n" .
        "│   └── Lesson 2: Scale Factors\n" .
        "└── Lesson 3: Parallel Lines\n" .
        "└── Lesson 4: Perpendicular Lines"
    );

    $workNodes = array_values(array_filter($nodes, fn (array $node) => $node['work_ordinal'] !== null));
    expect($workNodes)->toHaveCount(4)
        ->and($workNodes[0]['parent_position_path'])->toBe('001.001')
        ->and($workNodes[1]['parent_position_path'])->toBe('001.001')
        ->and($workNodes[2]['parent_position_path'])->toBe('001')
        ->and($workNodes[3]['work_ordinal'])->toBe(4);

    $repeated = \App\Services\V3\NotebookLM\DirectMapParser::parse(
        "Chapter: Unit 3\n" .
        "├── Mini Chapter: Parallel Lines\n" .
        "│   │   ├── Lesson 6: Relationship Between Slopes [1]"
    );
    expect($repeated[2]['parent_position_path'])->toBe('001.001')
        ->and($repeated[2]['work_ordinal'])->toBe(1);
});

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Direct Map');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Direct Map');

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

    // Setup approved framework version
    $this->frameworkPath = 'frameworks/steps.txt';
    $this->frameworkBytes = file_get_contents(base_path('steps.txt'));
    $this->frameworkSha256 = hash('sha256', $this->frameworkBytes);
    Storage::disk('v3_private')->put($this->frameworkPath, $this->frameworkBytes);

    $this->frameworkVersion = NotebookFrameworkVersion::create([
        'id' => (string) Str::uuid(),
        'profile' => 'expanded-root-v1',
        'body_path' => $this->frameworkPath,
        'body_sha256' => $this->frameworkSha256,
        'body_bytes' => strlen($this->frameworkBytes),
        'first_sha256' => hash('sha256', 'first'),
        'second_sha256' => hash('sha256', 'second'),
        'third_sha256' => hash('sha256', 'third'),
        'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
        'approved_by_user_id' => $this->admin->id,
        'approved_at' => now(),
    ]);

    $this->docContent = "%PDF-1.4 test math book";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-math-book.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->docContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($this->docContent),
        'page_count' => 5,
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
        'state_version' => 1,
        'pipeline_version' => 'notebooklm_combined_v1',
        'notebook_framework_version_id' => $this->frameworkVersion->id,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-map-direct',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'sourcing',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'extracted_source_id' => null,
        'source_mode' => 'original_pdf',
        'adapter_kind' => 'notebooklm_py',
        'adapter_version' => '0.8.4',
        'notebook_title' => "v3-case-{$this->case->id}",
        'source_title' => null,
        'notebook_id' => 'nb-direct-1',
        'source_id' => null,
        'state' => 'bound',
        'chat_configuration_state' => 'configured',
        'document_id' => $this->document->id,
        'framework_version_id' => $this->frameworkVersion->id,
        'transfer_status' => 'confirmed',
    ]);

    $this->pdfFile = KnowledgeSourceFile::forceCreate([
        'id' => (string) Str::uuid(),
        'binding_id' => $this->binding->id,
        'role' => 'educational_pdf',
        'title' => 'v3-pdf-test',
        'artifact_sha256' => $this->docSha256,
        'artifact_bytes' => strlen($this->docContent),
        'mime_type' => 'application/pdf',
        'provider_source_id' => 'src-pdf-1',
        'state' => 'ready',
    ]);

    $this->stepsFile = KnowledgeSourceFile::forceCreate([
        'id' => (string) Str::uuid(),
        'binding_id' => $this->binding->id,
        'role' => 'framework_steps',
        'title' => 'steps.txt',
        'artifact_sha256' => $this->frameworkSha256,
        'artifact_bytes' => strlen($this->frameworkBytes),
        'mime_type' => 'text/plain',
        'provider_source_id' => 'src-steps-1',
        'state' => 'ready',
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

test('Direct signed claim rejects a forged digest and a legacy kind without changing state', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $body = [
        'schema_version' => 1,
        'binding_ref' => $this->binding->id,
        'query_text' => file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/canonical_divide.txt')),
        'conversation_in' => null,
        'request_sha256' => str_repeat('0', 64),
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $path, $body))
        ->postJson($path, $body)->assertStatus(409);

    $legacyPath = "/api/internal/v1/lesson-queries/{$this->case->id}/first/1/claim";
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $legacyPath, $body))
        ->postJson($legacyPath, $body)->assertStatus(422);

    expect(LessonQuery::where('case_id', $this->case->id)->count())->toBe(0);
});

test('N10: Direct Divide result creates ready map with contiguous work ordinals and no Coverage required', function () {
    $divideMapText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_divide_map.txt'));

    // 1. Claim Divide query
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $claimPayload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/canonical_divide.txt')),
        'conversation_in' => null,
        'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
            $this->case->id, $this->binding->id, 'divide', 0, null, null,
            ['educational_pdf'], $this->frameworkSha256,
            $this->binding->response_format_sha256 ?? \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_DIVIDE_SHA256
        ),
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $claimResp->assertStatus(200);
    $queryRef = $claimResp->json('data.query_ref');

    // 2. Post Divide result
    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/result";
    $resultPayload = [
        'schema_version' => 1,
        'answer' => $divideMapText,
        'conversation_out' => 'conv-direct-1',
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $resultResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $resultResp->assertStatus(201);

    // 3. Verify LessonMap
    $lessonMap = LessonMap::where('case_id', $this->case->id)->first();
    expect($lessonMap)->not->toBeNull();
    expect($lessonMap->state)->toBe('ready'); // Direct map is immediately ready, no coverage query!
    expect($lessonMap->coverage_mode)->toBe('not_requested_direct_pdf');
    expect($lessonMap->page_count)->toBe(5); // Taken from Document page_count!
    expect($lessonMap->work_count)->toBe(5);

    // 4. Verify Nodes and Sibling Counter Across Kinds
    $nodes = LessonNode::where('map_id', $lessonMap->id)->orderBy('position_path')->get();

    // Chapter 1: 001
    $ch1 = $nodes->firstWhere('position_path', '001');
    expect($ch1)->not->toBeNull();
    expect($ch1->kind)->toBe('chapter');

    // Lesson 1.1: 001.001 (ordinal in parent: 1)
    $l11 = $nodes->firstWhere('position_path', '001.001');
    expect($l11)->not->toBeNull();
    expect($l11->kind)->toBe('lesson');
    expect($l11->ordinal_in_parent)->toBe(1);
    expect($l11->work_ordinal)->toBe(1);

    // Mini Chapter 1.1: 001.002 (ordinal in parent: 2 - single sibling counter!)
    $mc11 = $nodes->firstWhere('position_path', '001.002');
    expect($mc11)->not->toBeNull();
    expect($mc11->kind)->toBe('mini_chapter');
    expect($mc11->ordinal_in_parent)->toBe(2);
    expect($mc11->work_ordinal)->toBeNull(); // Has child lesson, not promoted

    // Lesson 1.2 under Mini Chapter 1.1: 001.002.001
    $l12 = $nodes->firstWhere('position_path', '001.002.001');
    expect($l12)->not->toBeNull();
    expect($l12->kind)->toBe('lesson');
    expect($l12->ordinal_in_parent)->toBe(1);
    expect($l12->work_ordinal)->toBe(2);

    // Mini Chapter 1.2: 001.003 (ordinal in parent: 3 - promoted!)
    $mc12 = $nodes->firstWhere('position_path', '001.003');
    expect($mc12)->not->toBeNull();
    expect($mc12->kind)->toBe('mini_chapter');
    expect($mc12->ordinal_in_parent)->toBe(3);
    expect($mc12->work_ordinal)->toBe(3); // Promoted to work item!

    // Chapter 2: 002
    // Lesson 2.1: 002.001
    $l21 = $nodes->firstWhere('position_path', '002.001');
    expect($l21)->not->toBeNull();
    expect($l21->work_ordinal)->toBe(4);

    // Lesson 2.2: 002.002 (repeated title "Ordered Pairs and Coordinates")
    $l22 = $nodes->firstWhere('position_path', '002.002');
    expect($l22)->not->toBeNull();
    expect($l22->title)->toBe('Ordered Pairs and Coordinates');
    expect($l22->work_ordinal)->toBe(5);

    // 5. Verify Cursor created for Lesson 1
    $cursor = LessonCursor::where('case_id', $this->case->id)->first();
    expect($cursor)->not->toBeNull();
    expect($cursor->current_work_ordinal)->toBe(1);
    expect($cursor->phase)->toBe('start_pending');
});

test('N10: MapContextController returns direct map context without extracted source', function () {
    // Direct map setup
    $lessonMap = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'ready',
        'coverage_mode' => 'not_requested_direct_pdf',
        'page_count' => 5,
        'work_count' => 1,
    ]);

    LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $lessonMap->id,
        'kind' => 'lesson',
        'position_path' => '001.001',
        'ordinal_in_parent' => 1,
        'title' => 'Intro',
        'work_ordinal' => 1,
    ]);

    $contextPath = "/api/internal/v1/map-activations/{$this->mapActivation->id}/context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $contextPath, []);
    $response = $this->withHeaders($headers)->get($contextPath);

    $response->assertStatus(200);
    expect($response->json('data.page_count'))->toBe(5);
    expect($response->json('data.map_state'))->toBe('ready');
    expect($response->json('data.work_count'))->toBe(1);
});

test('N10: Rejects invalid divide maps (code fences, empty chapters, orphan lessons)', function () {
    // 1. Claim Divide query
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $claimPayload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/canonical_divide.txt')),
        'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
            $this->case->id, $this->binding->id, 'divide', 0, null, null,
            ['educational_pdf'], $this->frameworkSha256,
            $this->binding->response_format_sha256 ?? \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_DIVIDE_SHA256
        ),
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $queryRef = $claimResp->json('data.query_ref');

    // Test code fences
    $codeFenceText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/divide_code_fences.txt'));
    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/result";
    $resultPayload = [
        'schema_version' => 1,
        'answer' => $codeFenceText,
        'conversation_out' => 'conv-direct-1',
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $response = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $response->assertStatus(422);
    $blocked = LessonQuery::find($queryRef);
    expect($blocked->state)->toBe('blocked')
        ->and($blocked->answer_sha256)->toBe(hash('sha256', $codeFenceText))
        ->and(Storage::disk('v3_private')->exists($blocked->answer_path))->toBeTrue()
        ->and(LessonMap::where('case_id', $this->case->id)->count())->toBe(0);

    // Test empty chapter
    $emptyChapterText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/divide_empty_chapter.txt'));
    $resultPayload['answer'] = $emptyChapterText;
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $response = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $response->assertStatus(422);

    // Test orphan lesson
    $orphanLessonText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/divide_orphan_lesson.txt'));
    $resultPayload['answer'] = $orphanLessonText;
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $response = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $response->assertStatus(422);
});
