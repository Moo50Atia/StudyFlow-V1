<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\NotebookLessonBundle;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Lessons\LessonCursorService;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Jobs\V3\NotifyLessonCompletionJob;
use App\Services\V3\Prompts\PromptContextReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    Queue::fake([NotifyLessonCompletionJob::class]);

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Combined Commit');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Combined Commit');

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

    $this->frameworkPath = 'frameworks/steps.txt';
    $this->frameworkBytes = file_get_contents(base_path('steps.txt'));
    $this->frameworkSha256 = hash('sha256', $this->frameworkBytes);
    Storage::disk('v3_private')->put($this->frameworkPath, $this->frameworkBytes);

    $this->firstSha = hash('sha256', 'first-slice');
    $this->secondSha = hash('sha256', 'second-slice');
    $this->thirdSha = hash('sha256', 'third-slice');

    $this->frameworkVersion = NotebookFrameworkVersion::create([
        'id' => (string) Str::uuid(),
        'profile' => 'expanded-root-v1',
        'body_path' => $this->frameworkPath,
        'body_sha256' => $this->frameworkSha256,
        'body_bytes' => strlen($this->frameworkBytes),
        'first_sha256' => $this->firstSha,
        'second_sha256' => $this->secondSha,
        'third_sha256' => $this->thirdSha,
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
        'page_count' => 10,
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
        'run_id' => 'run-commit-direct',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'sourcing',
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

    $this->mapActivation = MapActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->lessonMap = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'ready',
        'coverage_mode' => 'not_requested_direct_pdf',
        'page_count' => 10,
        'work_count' => 2,
    ]);

    $this->node1 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->lessonMap->id,
        'kind' => 'lesson',
        'title' => 'The Number Line and Real Numbers',
        'position_path' => '001.001',
        'work_ordinal' => 1,
        'ordinal_in_parent' => 1,
    ]);

    $this->node2 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->lessonMap->id,
        'kind' => 'lesson',
        'title' => 'Absolute Value and Inequalities',
        'position_path' => '001.002',
        'work_ordinal' => 2,
        'ordinal_in_parent' => 2,
    ]);

    // Execution query recorded
    $this->executionQuery = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->lessonMap->id,
        'binding_id' => $this->binding->id,
        'kind' => 'execution',
        'target_work_ordinal' => 0,
        'expected_cursor_version' => 1,
        'state' => 'recorded',
        'request_sha256' => hash('sha256', 'execution-req'),
        'answer_sha256' => hash('sha256', 'System Ready'),
        'conversation_out' => 'convo-global-1',
    ]);

    // Lesson cursor at ready_to_start, version 2
    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->lessonMap->id,
        'current_work_ordinal' => 1,
        'phase' => 'ready_to_start',
        'conversation_id' => 'convo-global-1',
        'version' => 2,
    ]);
});

test('Signed combined callback rejects conversation rotation and a different mapped lesson', function () {
    $claim = app(\App\Services\V3\NotebookLM\DirectQueryLedger::class)->claimQuery(
        caseId: $this->case->id,
        kind: 'lesson_start',
        workOrdinal: 1,
        bindingRef: $this->binding->id,
        expectedCursorVersion: 2,
        conversationIn: 'convo-global-1'
    );
    $queryRef = $claim['query_ref'];
    $path = "/api/internal/v1/lesson-queries/{$queryRef}/combined-result";
    $answer = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
    $body = [
        'schema_version' => 1,
        'expected_cursor_version' => 3,
        'answer' => $answer,
        'answer_sha256' => hash('sha256', $answer),
        'conversation_out' => 'different-conversation',
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $path, $body))
        ->postJson($path, $body)->assertStatus(409)->assertJsonPath('error.code', 'protocol_conflict');
    expect(LessonQuery::find($queryRef)->state)->toBe('sending');

    $body['conversation_out'] = 'convo-global-1';
    $body['answer'] = str_replace('W1 | The Number Line and Real Numbers', 'W1 | Different Lesson', $answer);
    $body['answer_sha256'] = hash('sha256', $body['answer']);
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $path, $body))
        ->postJson($path, $body)->assertStatus(422)->assertJsonPath('error.code', 'protocol_validation');
    expect(LessonQuery::find($queryRef)->state)->toBe('blocked')
        ->and(NotebookLessonBundle::where('query_id', $queryRef)->count())->toBe(0)
        ->and(TeacherSection::where('query_id', $queryRef)->count())->toBe(0);
});

test('N12: Valid combined reply commits atomically and preserves all fields', function () {
    $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
    $validCombinedText = str_replace(
        'W1 | The Number Line and Real Numbers',
        'W1 | Lesson 1: The Number Line and Real Numbers',
        $validCombinedText
    );
    // NotebookLM may put the opening quote and first prompt line on the
    // Lec/Sec header. The PHP callback parser must preserve it like Python.
    $validCombinedText = str_replace(
        'Lec1 (Sec1):' . "\n" . '"Comparison: Real Number Line Continuum vs Discrete Sets',
        'Lec1 (Sec1): "Comparison: Real Number Line Continuum vs Discrete Sets',
        $validCombinedText
    );
    $validCombinedText = str_replace(
        '"Comparison: Real Number Line Continuum vs Discrete Sets'."\n".'Learning Target:',
        '"Scene Type: Decomposition'."\n".'Learning Target:',
        $validCombinedText
    );
    $validCombinedText = str_replace(
        'Section Identity: Real Number Representation',
        "Section Identity\n- Primary Learning Objective: Real Number Representation",
        $validCombinedText
    );
    $validCombinedText = str_replace('Section 1:', 'Section 1: Real Number Representation', $validCombinedText);
    $validCombinedText = str_replace(
        "Lec1 (Sec1):\n\"Interactive number line exploring real number continuum and density.\"",
        "Lec1 (Sec1): \"Interactive number line exploring real number continuum and density.\"",
        $validCombinedText
    );
    $validCombinedText = str_replace(
        '✅ Lesson [1] Completed.',
        '✅ Lesson [4] Completed.',
        $validCombinedText
    );
    $fieldOrdinal = 0;
    $numberedLines = [];
    foreach (explode("\n", $validCombinedText) as $line) {
        if (preg_match('/^Section\s+[0-9]+\s*:/i', trim($line))) {
            $fieldOrdinal = 0;
        }
        $matchedField = false;
        foreach (\App\Services\V3\NotebookLM\CombinedLessonValidator::FIRST_LABEL_SPECS as $spec) {
            if (preg_match($spec['pattern'], trim($line))) {
                $fieldOrdinal++;
                $numberedLines[] = $fieldOrdinal.'. '.$line;
                $matchedField = true;
                break;
            }
        }
        if (! $matchedField) {
            $numberedLines[] = $line;
        }
    }
    $validCombinedText = implode("\n", $numberedLines);
    $answerSha = hash('sha256', $validCombinedText);

    // 1. Claim lesson_start query
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/lesson_start/1/claim";
    $claimPayload = [
        'schema_version' => 1,
        'binding_ref' => $this->binding->id,
        'expected_cursor_version' => 2,
        'conversation_in' => 'convo-global-1',
        'query_text' => 'Start with First lesson',
        'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
            $this->case->id, $this->binding->id, 'lesson_start', 1, 2, 'convo-global-1',
            ['educational_pdf', 'framework_steps'], $this->frameworkSha256,
            $this->binding->response_format_sha256 ?? \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            hash('sha256', 'Start with First lesson')
        ),
        'history_length_before' => 2,
        'history_anchor_sha256' => hash('sha256', 'anchor-1'),
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $claimResp->assertStatus(200);
    $queryRef = $claimResp->json('data.query_ref');
    $claimedCursorVersion = $claimResp->json('data.claimed_cursor_version');
    expect($claimedCursorVersion)->toBe(3);

    // Check cursor updated to processing_bundle, version 3
    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('processing_bundle')
        ->and($this->cursor->version)->toBe(3);

    // 2. Post combined-result
    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/combined-result";
    $resultPayload = [
        'schema_version' => 1,
        'expected_cursor_version' => 3,
        'answer' => $validCombinedText,
        'answer_sha256' => $answerSha,
        'conversation_out' => 'convo-global-1',
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $resultResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);

    $resultResp->assertStatus(201);
    $data = $resultResp->json('data');

    expect($data)->toHaveKeys(['bundle_ref', 'completion_ref', 'work_ordinal', 'cursor_version', 'section_refs', 'original_prompt_refs'])
        ->and($data['work_ordinal'])->toBe(1)
        ->and($data['cursor_version'])->toBe(4)
        ->and(count($data['section_refs']))->toBe(2)
        ->and(count($data['original_prompt_refs']))->toBe(2);

    $bundleRef = $data['bundle_ref'];
    $completionRef = $data['completion_ref'];
    $sectionRefs = $data['section_refs'];
    $promptRefs = $data['original_prompt_refs'];

    // 3. Verify TeacherSection rows
    $sections = TeacherSection::where('work_node_id', $this->node1->id)
        ->orderBy('ordinal_in_work')
        ->get();
    expect($sections)->toHaveCount(2);

    $sec1 = $sections[0];
    expect($sec1->id)->toBe($sectionRefs[0])
        ->and($sec1->section_identity)->toContain('Primary Learning Objective: Real Number Representation')
        ->and($sec1->core_understanding)->toContain('Every real number corresponds to a unique point')
        ->and($sec1->colloquial_egyptian_explanation)->toContain('خط الأعداد ده خط مستمر ملوش نهاية')
        ->and($sec1->laws_if_any)->toContain('\forall x \in \mathbb{R}')
        ->and($sec1->first_prompt_sha256)->toBe($this->firstSha)
        ->and($sec1->answer_sha256)->toBe($answerSha);

    $sec2 = $sections[1];
    expect($sec2->id)->toBe($sectionRefs[1])
        ->and($sec2->section_identity)->toBe('Order and Absolute Value')
        ->and($sec2->laws_if_any)->toContain('|a - b| = \sqrt{(a - b)^2} \ge 0');

    // 4. Verify PromptWorkItem
    $workItem = PromptWorkItem::where('case_id', $this->case->id)
        ->where('work_ordinal', 1)
        ->first();
    expect($workItem)->not->toBeNull()
        ->and($workItem->state)->toBe('receipt_committed')
        ->and($workItem->section_query_id)->toBe($queryRef)
        ->and($workItem->expected_cursor_version)->toBe(3)
        ->and($workItem->section_refs)->toBe($sectionRefs)
        ->and($workItem->completion_ref)->toBe($completionRef);

    // 5. Verify PromptTurn rows (execution, second, third)
    $turns = PromptTurn::where('work_item_id', $workItem->id)->get()->keyBy('step');
    expect($turns)->toHaveKeys(['execution', 'second', 'third']);

    $execTurn = $turns['execution'];
    expect($execTurn->origin)->toBe('combined_reuse')
        ->and($execTurn->send_count)->toBe(0)
        ->and($execTurn->state)->toBe('recorded')
        ->and($execTurn->origin_query_id)->toBe($this->executionQuery->id);

    $secondTurn = $turns['second'];
    expect($secondTurn->origin)->toBe('combined_reuse')
        ->and($secondTurn->send_count)->toBe(0)
        ->and($secondTurn->state)->toBe('recorded')
        ->and($secondTurn->origin_query_id)->toBe($queryRef)
        ->and(is_array($secondTurn->parsed_mappings))->toBeTrue()
        ->and(Storage::disk('v3_private')->exists($secondTurn->answer_path))->toBeTrue();

    $thirdTurn = $turns['third'];
    expect($thirdTurn->origin)->toBe('combined_reuse')
        ->and($thirdTurn->send_count)->toBe(0)
        ->and($thirdTurn->state)->toBe('recorded')
        ->and($thirdTurn->origin_query_id)->toBe($queryRef)
        ->and(Storage::disk('v3_private')->exists($thirdTurn->answer_path))->toBeTrue();

    // 6. Verify ScenePromptVersion rows
    $promptVersions = ScenePromptVersion::where('work_item_id', $workItem->id)
        ->orderBy('created_at')
        ->get();
    expect($promptVersions)->toHaveCount(2);

    $pv1 = $promptVersions->firstWhere('section_id', $sec1->id);
    expect($pv1)->not->toBeNull()
        ->and($pv1->id)->toBe($promptRefs[0])
        ->and($pv1->origin)->toBe('third')
        ->and($pv1->version_no)->toBe(1)
        ->and($pv1->scene_type)->toBe('Decomposition')
        ->and($pv1->canonical_third_sha256)->toBe($this->thirdSha)
        ->and($pv1->framework_version_id)->toBe($this->frameworkVersion->id)
        ->and(Storage::disk('v3_private')->exists($pv1->body_path))->toBeTrue();

    $pv2 = $promptVersions->firstWhere('section_id', $sec2->id);
    expect($pv2)->not->toBeNull()
        ->and($pv2->id)->toBe($promptRefs[1])
        ->and($pv2->scene_type)->toBe('Process');

    // 7. Verify LessonCompletion
    $completion = LessonCompletion::where('case_id', $this->case->id)
        ->where('work_ordinal', 1)
        ->first();
    expect($completion)->not->toBeNull()
        ->and($completion->completion_ref)->toBe($completionRef)
        ->and($completion->cursor_version)->toBe(4)
        ->and($completion->signal_state)->toBe('committed')
        ->and($completion->section_refs)->toBe($sectionRefs)
        ->and($completion->third_output_refs)->toBe($promptRefs);

    $expectedReceipt = LessonCursorService::computeCompletionDigest(
        caseId: $this->case->id,
        mapRef: $this->lessonMap->id,
        workOrdinal: 1,
        expectedCursorVersion: 3,
        sectionRefs: $sectionRefs,
        thirdOutputRefs: $promptRefs,
        finalConversationRef: 'convo-global-1',
        completionRef: $completionRef,
    );
    expect($completion->completion_sha256)->toBe($expectedReceipt);

    // 8. Verify NotebookLessonBundle
    $bundle = NotebookLessonBundle::where('id', $bundleRef)->first();
    expect($bundle)->not->toBeNull()
        ->and($bundle->query_id)->toBe($queryRef)
        ->and($bundle->work_node_id)->toBe($this->node1->id)
        ->and($bundle->work_item_id)->toBe($workItem->id)
        ->and($bundle->completion_id)->toBe($completion->id)
        ->and($bundle->work_ordinal)->toBe(1)
        ->and($bundle->framework_version_id)->toBe($this->frameworkVersion->id)
        ->and($bundle->answer_sha256)->toBe($answerSha)
        ->and($bundle->committed_cursor_version)->toBe(4);

    // 9. Verify LessonCursor advanced to ready_to_continue
    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('ready_to_continue')
        ->and($this->cursor->version)->toBe(4)
        ->and($this->cursor->current_work_ordinal)->toBe(2)
        ->and($this->cursor->last_completion_id)->toBe($completion->id)
        ->and($this->cursor->conversation_id)->toBe('convo-global-1');

    // 10. Verify LessonQuery updated to recorded
    $query = LessonQuery::find($queryRef);
    expect($query->state)->toBe('recorded')
        ->and($query->answer_sha256)->toBe($answerSha)
        ->and($query->conversation_out)->toBe('convo-global-1')
        ->and(Storage::disk('v3_private')->exists($query->answer_path))->toBeTrue();
});

test('N13: Atomicity: zero durable partial data when transaction aborts, and known reply can be reposted', function () {
    $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
    $answerSha = hash('sha256', $validCombinedText);

    // Claim lesson_start
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/lesson_start/1/claim";
    $claimPayload = [
        'schema_version' => 1,
        'binding_ref' => $this->binding->id,
        'expected_cursor_version' => 2,
        'conversation_in' => 'convo-global-1',
        'query_text' => 'Start with First lesson',
        'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
            $this->case->id, $this->binding->id, 'lesson_start', 1, 2, 'convo-global-1',
            ['educational_pdf', 'framework_steps'], $this->frameworkSha256,
            $this->binding->response_format_sha256 ?? \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            hash('sha256', 'Start with First lesson')
        ),
        'history_length_before' => 2,
        'history_anchor_sha256' => hash('sha256', 'anchor-1'),
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $queryRef = $claimResp->json('data.query_ref');

    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/combined-result";
    $resultPayload = [
        'schema_version' => 1,
        'expected_cursor_version' => 3,
        'answer' => $validCombinedText,
        'answer_sha256' => $answerSha,
        'conversation_out' => 'convo-global-1',
    ];

    // Inject temporary failure by listening to TeacherSection creating event and throwing
    $failOnce = true;
    \App\Models\V3\TeacherSection::creating(function () use (&$failOnce) {
        if ($failOnce) {
            $failOnce = false;
            throw new \RuntimeException("Injected database failure during section creation");
        }
    });

    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $respFail = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $respFail->assertStatus(500);

    // Assert ALL tables have zero partial rows
    expect(TeacherSection::where('case_id', $this->case->id)->count())->toBe(0)
        ->and(PromptWorkItem::where('case_id', $this->case->id)->count())->toBe(0)
        ->and(PromptTurn::where('origin_query_id', $queryRef)->count())->toBe(0)
        ->and(ScenePromptVersion::where('case_id', $this->case->id)->count())->toBe(0)
        ->and(LessonCompletion::where('case_id', $this->case->id)->count())->toBe(0)
        ->and(NotebookLessonBundle::where('case_id', $this->case->id)->count())->toBe(0);

    // Cursor must still be at version 3, processing_bundle
    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('processing_bundle')
        ->and($this->cursor->version)->toBe(3);

    // Query must still be in sending state (not recorded)
    $query = LessonQuery::find($queryRef);
    expect($query->state)->toBe('sending');

    // Repost known valid reply succeeds cleanly
    $respSuccess = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $respSuccess->assertStatus(201);

    expect(TeacherSection::where('case_id', $this->case->id)->count())->toBe(2)
        ->and(NotebookLessonBundle::where('case_id', $this->case->id)->count())->toBe(1);
});

test('N14: Exact duplicate returns original refs/version; conflict on changed body, conversation, or cursor', function () {
    $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
    $answerSha = hash('sha256', $validCombinedText);

    // Claim lesson_start
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/lesson_start/1/claim";
    $claimPayload = [
        'schema_version' => 1,
        'binding_ref' => $this->binding->id,
        'expected_cursor_version' => 2,
        'conversation_in' => 'convo-global-1',
        'query_text' => 'Start with First lesson',
        'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
            $this->case->id, $this->binding->id, 'lesson_start', 1, 2, 'convo-global-1',
            ['educational_pdf', 'framework_steps'], $this->frameworkSha256,
            $this->binding->response_format_sha256 ?? \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            hash('sha256', 'Start with First lesson')
        ),
        'history_length_before' => 2,
        'history_anchor_sha256' => hash('sha256', 'anchor-1'),
    ];
    $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
    $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
    $queryRef = $claimResp->json('data.query_ref');

    $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/combined-result";
    $resultPayload = [
        'schema_version' => 1,
        'expected_cursor_version' => 3,
        'answer' => $validCombinedText,
        'answer_sha256' => $answerSha,
        'conversation_out' => 'convo-global-1',
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
    $firstResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $firstResp->assertStatus(201);
    $firstData = $firstResp->json('data');

    // 1. Exact replay returns 200 with same refs
    $replayResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);
    $replayResp->assertStatus(200);
    expect($replayResp->json('data'))->toBe($firstData);

    // Assert row counts did not increase
    expect(TeacherSection::where('case_id', $this->case->id)->count())->toBe(2)
        ->and(NotebookLessonBundle::where('case_id', $this->case->id)->count())->toBe(1);

    // 2. Replay with changed conversation_out returns 409
    $conflictConvoPayload = array_merge($resultPayload, ['conversation_out' => 'convo-different']);
    $conflictConvoHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $conflictConvoPayload);
    $convoResp = $this->withHeaders($conflictConvoHeaders)->postJson($resultPath, $conflictConvoPayload);
    $convoResp->assertStatus(409);

    // 3. Replay with changed answer / answer_sha returns 409
    $conflictAnswerPayload = array_merge($resultPayload, [
        'answer' => $validCombinedText . "\nExtra",
        'answer_sha256' => hash('sha256', $validCombinedText . "\nExtra"),
    ]);
    $conflictAnswerHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $conflictAnswerPayload);
    $answerResp = $this->withHeaders($conflictAnswerHeaders)->postJson($resultPath, $conflictAnswerPayload);
    $answerResp->assertStatus(409);

    // 4. Stale cursor version returns 409
    $stalePayload = array_merge($resultPayload, ['expected_cursor_version' => 999]);
    $staleHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $stalePayload);
    $staleResp = $this->withHeaders($staleHeaders)->postJson($resultPath, $stalePayload);
    $staleResp->assertStatus(409);
});

test('N15: Rejects malformed combined replies, retains private raw evidence, and blocks query', function () {
    $malformedFiles = [
        'combined_final_only.txt',
        'combined_missing_delimiter.txt',
        'combined_missing_first_fields.txt',
        'combined_mismatched_section_count.txt',
    ];

    foreach ($malformedFiles as $fileName) {
        $filePath = base_path("tests/Fixtures/V3/NotebookLM/{$fileName}");
        $content = file_get_contents($filePath);
        $contentSha = hash('sha256', $content);

        // Reset cursor to ready_to_start, version 2 for new claim
        $this->cursor->update([
            'phase' => 'ready_to_start',
            'version' => 2,
        ]);

        // Create new query
        $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/lesson_start/1/claim";
        $claimPayload = [
            'schema_version' => 1,
            'binding_ref' => $this->binding->id,
            'expected_cursor_version' => 2,
            'conversation_in' => 'convo-global-1',
            'query_text' => 'Start with First lesson',
            'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
                $this->case->id, $this->binding->id, 'lesson_start', 1, 2, 'convo-global-1',
                ['educational_pdf', 'framework_steps'], $this->frameworkSha256,
                $this->binding->response_format_sha256 ?? \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_SHA256,
                hash('sha256', 'Start with First lesson')
            ),
            'history_length_before' => 2,
            'history_anchor_sha256' => hash('sha256', 'anchor-1'),
        ];
        $claimHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimPayload);
        $claimResp = $this->withHeaders($claimHeaders)->postJson($claimPath, $claimPayload);
        $queryRef = $claimResp->json('data.query_ref');

        $resultPath = "/api/internal/v1/lesson-queries/{$queryRef}/combined-result";
        $resultPayload = [
            'schema_version' => 1,
            'expected_cursor_version' => 3,
            'answer' => $content,
            'answer_sha256' => $contentSha,
            'conversation_out' => 'convo-global-1',
        ];
        $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultPayload);
        $resp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultPayload);

        $resp->assertStatus(422);

        // Assert 0 rows in production tables
        expect(TeacherSection::where('query_id', $queryRef)->count())->toBe(0)
            ->and(PromptWorkItem::where('section_query_id', $queryRef)->count())->toBe(0)
            ->and(LessonCompletion::where('case_id', $this->case->id)->count())->toBe(0)
            ->and(NotebookLessonBundle::where('query_id', $queryRef)->count())->toBe(0);

        // Assert query state is blocked, and raw answer is stored on private disk
        $query = LessonQuery::find($queryRef);
        expect($query->state)->toBe('blocked')
            ->and($query->error_code)->not->toBeNull()
            ->and(Storage::disk('v3_private')->exists($query->answer_path))->toBeTrue();

        // Cursor did NOT advance
        $this->cursor->refresh();
        expect($this->cursor->phase)->not->toBe('ready_to_continue')
            ->and($this->cursor->phase)->not->toBe('complete');

        // Clean up query for next loop iteration
        $query->delete();
    }
});
