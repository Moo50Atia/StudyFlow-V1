<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Jobs\V3\NotifyLessonCompletionJob;
use App\Models\V3\CaseOperationControl;
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
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use App\Services\V3\NotebookLM\DirectQueryLedger;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class DirectRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected $teacher;
    protected $admin;
    protected $settingsVersion;
    protected $frameworkVersion;
    protected $document;
    protected $processingCase;
    protected $binding;
    protected $map;
    protected $cursor;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');
        Queue::fake([NotifyLessonCompletionJob::class]);

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Direct Recovery');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Direct Recovery');

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

        $frameworkBytes = file_get_contents(base_path('steps.txt'));
        $frameworkSha256 = hash('sha256', $frameworkBytes);
        Storage::disk('v3_private')->put('frameworks/steps.txt', $frameworkBytes);

        $this->frameworkVersion = NotebookFrameworkVersion::create([
            'id' => (string) Str::uuid(),
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/steps.txt',
            'body_sha256' => $frameworkSha256,
            'body_bytes' => strlen($frameworkBytes),
            'first_sha256' => hash('sha256', 'first-slice'),
            'second_sha256' => hash('sha256', 'second-slice'),
            'third_sha256' => hash('sha256', 'third-slice'),
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $docContent = "%PDF-1.4 test math book";
        $docSha256 = hash('sha256', $docContent);
        $docPath = 'accepted/test-math-book.pdf';
        Storage::disk('v3_private')->put($docPath, $docContent);

        $this->document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'sha256' => $docSha256,
            'byte_size' => strlen($docContent),
            'page_count' => 12,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => now(),
        ]);

        $this->processingCase = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'document_id' => $this->document->id,
            'settings_version_id' => $this->settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'pipeline_version' => 'notebooklm_combined_v1',
            'notebook_framework_version_id' => $this->frameworkVersion->id,
            'notebook_policy' => ['chat_response_length' => 'longer'],
            'state' => 'running',
            'state_version' => 1,
        ]);

        WorkflowLink::forceCreate([
            'case_id' => $this->processingCase->id,
            'workflow_id' => "v3-case-{$this->processingCase->id}",
            'run_id' => 'run-recov-direct',
            'settings_version_id' => $this->settingsVersion->id,
            'settings_checksum' => $this->settingsVersion->checksum,
            'start_state' => 'started',
            'stage_code' => 'sourcing',
        ]);

        $this->binding = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'source_mode' => 'original_pdf',
            'adapter_kind' => 'notebooklm_py',
            'adapter_version' => '0.8.4',
            'state' => 'bound',
            'document_id' => $this->document->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'notebook_title' => "v3-case-{$this->processingCase->id}",
            'notebook_id' => 'nb-test-direct-recov',
            'chat_configuration_state' => 'configured',
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
        ]);

        KnowledgeSourceFile::forceCreate([
            'id' => (string) Str::uuid(),
            'binding_id' => $this->binding->id,
            'role' => 'educational_pdf',
            'title' => 'test-math-book.pdf',
            'mime_type' => 'application/pdf',
            'artifact_sha256' => $docSha256,
            'artifact_bytes' => strlen($docContent),
            'provider_source_id' => 'src-pdf-recov',
            'state' => 'ready',
        ]);

        KnowledgeSourceFile::forceCreate([
            'id' => (string) Str::uuid(),
            'binding_id' => $this->binding->id,
            'role' => 'framework_steps',
            'title' => 'steps.txt',
            'mime_type' => 'text/plain; charset=utf-8',
            'artifact_sha256' => $frameworkSha256,
            'artifact_bytes' => strlen($frameworkBytes),
            'provider_source_id' => 'src-steps-recov',
            'state' => 'ready',
        ]);

        $activation = MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'binding_id' => $this->binding->id,
            'workflow_id' => "v3-case-{$this->processingCase->id}",
            'state' => 'accepted',
        ]);

        $this->map = LessonMap::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'binding_id' => $this->binding->id,
            'activation_id' => $activation->id,
            'state' => 'ready',
            'coverage_mode' => 'not_requested_direct_pdf',
            'page_count' => 12,
            'work_count' => 1,
        ]);

        LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $this->map->id,
            'work_ordinal' => 1,
            'position_path' => '001.001',
            'ordinal_in_parent' => 1,
            'kind' => 'lesson',
            'title' => 'The Number Line and Real Numbers',
        ]);

        LessonQuery::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'map_id' => $this->map->id,
            'binding_id' => $this->binding->id,
            'kind' => 'execution',
            'target_work_ordinal' => 0,
            'expected_cursor_version' => 1,
            'state' => 'recorded',
            'request_sha256' => hash('sha256', 'execution-req'),
            'answer_sha256' => hash('sha256', 'System Ready'),
            'conversation_out' => 'convo-global-recov',
        ]);

        $this->cursor = LessonCursor::forceCreate([
            'case_id' => $this->processingCase->id,
            'map_id' => $this->map->id,
            'current_work_ordinal' => 1,
            'phase' => 'ready_to_start',
            'conversation_id' => 'convo-global-recov',
            'version' => 2,
        ]);
    }

    public function test_n17_uncertain_query_endpoint_updates_state_and_preserves_counts_and_digests(): void
    {
        $ledger = app(DirectQueryLedger::class);
        $claim = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-recov',
        );

        $this->assertSame('send_now', $claim['disposition']);
        $queryRef = $claim['query_ref'];

        $queryBefore = LessonQuery::find($queryRef);
        $this->assertSame(1, $queryBefore->send_count);
        $this->assertSame('sending', $queryBefore->state);
        $this->assertSame(3, $queryBefore->expected_cursor_version);
        $originalRequestSha = $queryBefore->request_sha256;

        // Post to uncertain endpoint
        $path = "/api/internal/v1/lesson-queries/{$queryRef}/uncertain";
        $payload = [
            'schema_version' => 1,
            'error_code' => 'provider_uncertain',
        ];

        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
        $response = $this->withHeaders($headers)
            ->postJson($path, $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'query_ref' => $queryRef,
                'state' => 'uncertain',
                'error_code' => 'provider_uncertain',
            ],
        ]);

        // Verify database invariants preserved
        $queryAfter = LessonQuery::find($queryRef);
        $this->assertSame('uncertain', $queryAfter->state);
        $this->assertSame('provider_uncertain', $queryAfter->error_code);
        $this->assertSame(1, $queryAfter->send_count, 'send_count must never be reset');
        $this->assertSame($originalRequestSha, $queryAfter->request_sha256, 'request_sha256 must be preserved');
        $this->assertSame(3, $queryAfter->expected_cursor_version, 'expected_cursor_version must be preserved');

        // Subsequent claim must return reconcile_only, NOT send_now
        $reclaim = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-recov',
        );

        $this->assertSame('reconcile_only', $reclaim['disposition']);
        $this->assertSame($queryRef, $reclaim['query_ref']);

        // send_count remains 1
        $this->assertSame(1, LessonQuery::find($queryRef)->send_count);
    }

    public function test_n17_reconciler_reposts_known_exact_response_and_does_not_resend(): void
    {
        $ledger = app(DirectQueryLedger::class);
        $claim = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-recov',
        );
        $queryRef = $claim['query_ref'];

        // Mark query uncertain
        LessonQuery::where('id', $queryRef)->update([
            'state' => 'uncertain',
            'error_code' => 'network_timeout',
        ]);

        // Place known valid answer in storage
        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);
        $answerPath = "answers/{$this->processingCase->id}/{$queryRef}_{$answerSha}.txt";
        Storage::disk('v3_private')->put($answerPath, $validCombinedText);

        LessonQuery::where('id', $queryRef)->update([
            'answer_path' => $answerPath,
            'answer_sha256' => $answerSha,
            'answer_bytes' => strlen($validCombinedText),
            'conversation_out' => 'convo-global-recov',
        ]);

        // Mock bridge for completion notification
        $bridgeMock = \Mockery::mock(BridgeClient::class);
        $bridgeMock->shouldReceive('notifyLessonReady')
            ->andReturn(['status' => 202, 'data' => ['accepted' => true]]);
        $this->app->instance(BridgeClient::class, $bridgeMock);

        // Run reconciliation command
        $exitCode = Artisan::call('v3:reconcile-notebook-protocol');
        $this->assertSame(0, $exitCode);

        // Bundle is created and cursor advanced without a new send
        $this->assertSame(1, NotebookLessonBundle::where('case_id', $this->processingCase->id)->count());
        $cursor = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(4, $cursor->version);
        $this->assertSame('complete', $cursor->phase); // 1 total unit, so work 1 completion completes the cursor

        // send_count is still 1
        $this->assertSame(1, LessonQuery::find($queryRef)->send_count);
    }

    public function test_n18_reconciler_respects_hold_cancel_fence(): void
    {
        $ledger = app(DirectQueryLedger::class);
        $claim = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-recov',
        );
        $queryRef = $claim['query_ref'];

        // Mark query uncertain with staged answer
        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);
        $answerPath = "answers/{$this->processingCase->id}/{$queryRef}_{$answerSha}.txt";
        Storage::disk('v3_private')->put($answerPath, $validCombinedText);

        LessonQuery::where('id', $queryRef)->update([
            'state' => 'uncertain',
            'error_code' => 'network_timeout',
            'answer_path' => $answerPath,
            'answer_sha256' => $answerSha,
            'answer_bytes' => strlen($validCombinedText),
            'conversation_out' => 'convo-global-recov',
        ]);

        // Put case on hold
        CaseOperationControl::forceCreate([
            'case_id' => $this->processingCase->id,
            'mode' => 'held',
            'control_version' => 1,
        ]);

        // Run reconciliation
        Artisan::call('v3:reconcile-notebook-protocol');

        // Due to hold fence: 0 bundles created, cursor NOT advanced
        $this->assertSame(0, NotebookLessonBundle::where('case_id', $this->processingCase->id)->count());
        $cursor = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(3, $cursor->version); // still claimed version 3
        $this->assertSame('processing_bundle', $cursor->phase);

        // Remove hold
        CaseOperationControl::where('case_id', $this->processingCase->id)->update(['mode' => 'normal']);

        // Mock bridge
        $bridgeMock = \Mockery::mock(BridgeClient::class);
        $bridgeMock->shouldReceive('notifyLessonReady')
            ->andReturn(['status' => 202, 'data' => ['accepted' => true]]);
        $this->app->instance(BridgeClient::class, $bridgeMock);

        // Reconcile again -> now succeeds
        Artisan::call('v3:reconcile-notebook-protocol');

        $this->assertSame(1, NotebookLessonBundle::where('case_id', $this->processingCase->id)->count());
        $cursorAfter = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(4, $cursorAfter->version);
    }

    public function test_n17_duplicate_reconciliation_deliveries_are_idempotent_and_do_not_recreate_bundle(): void
    {
        $ledger = app(DirectQueryLedger::class);
        $claim = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-recov',
        );
        $queryRef = $claim['query_ref'];

        // Mark query uncertain with staged answer
        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);
        $answerPath = "answers/{$this->processingCase->id}/{$queryRef}_{$answerSha}.txt";
        Storage::disk('v3_private')->put($answerPath, $validCombinedText);

        LessonQuery::where('id', $queryRef)->update([
            'state' => 'uncertain',
            'error_code' => 'network_timeout',
            'answer_path' => $answerPath,
            'answer_sha256' => $answerSha,
            'answer_bytes' => strlen($validCombinedText),
            'conversation_out' => 'convo-global-recov',
        ]);

        $bridgeMock = \Mockery::mock(BridgeClient::class);
        $bridgeMock->shouldReceive('notifyLessonReady')
            ->andReturn(['status' => 202, 'data' => ['accepted' => true]]);
        $this->app->instance(BridgeClient::class, $bridgeMock);

        // First reconciliation delivery
        $res1 = Artisan::call('v3:reconcile-notebook-protocol');
        $this->assertSame(0, $res1);
        $this->assertSame(1, NotebookLessonBundle::where('case_id', $this->processingCase->id)->count());
        $this->assertSame(1, LessonCompletion::where('case_id', $this->processingCase->id)->count());
        $cursor1 = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(4, $cursor1->version);
        $this->assertSame(1, LessonQuery::find($queryRef)->send_count);

        // Second (duplicate) reconciliation delivery
        $res2 = Artisan::call('v3:reconcile-notebook-protocol');
        $this->assertSame(0, $res2);
        $this->assertSame(1, NotebookLessonBundle::where('case_id', $this->processingCase->id)->count(), 'Duplicate reconciliation must not create duplicate bundle');
        $this->assertSame(1, LessonCompletion::where('case_id', $this->processingCase->id)->count(), 'Duplicate reconciliation must not create duplicate completion');
        $cursor2 = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(4, $cursor2->version, 'Cursor version must remain unchanged on duplicate reconciliation');
        $this->assertSame(1, LessonQuery::find($queryRef)->send_count, 'send_count must remain 1');
    }

    public function test_n17_lost_continue_reply_reconciled_without_resend_and_advances_cursor(): void
    {
        // Update map to have 2 work items and add second lesson node
        $this->map->update(['work_count' => 2]);
        LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $this->map->id,
            'work_ordinal' => 2,
            'position_path' => '001.002',
            'ordinal_in_parent' => 2,
            'kind' => 'lesson',
            'title' => 'Absolute Value and Distances',
        ]);

        $ledger = app(DirectQueryLedger::class);

        // Step 1: Complete Work 1 normally
        $claim1 = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-recov',
        );
        $validCombinedTextW1 = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $acceptService = app(\App\Services\V3\NotebookLM\AcceptCombinedLesson::class);
        $acceptService->acceptCombinedLesson(
            queryRef: $claim1['query_ref'],
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedTextW1,
                'answer_sha256' => hash('sha256', $validCombinedTextW1),
                'conversation_out' => 'convo-global-recov',
            ],
        );

        $cursorAfterW1 = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(4, $cursorAfterW1->version);
        $this->assertSame('ready_to_continue', $cursorAfterW1->phase);
        $this->assertSame(2, $cursorAfterW1->current_work_ordinal);

        // Step 2: Claim Work 2 (lesson_continue)
        $claim2 = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_continue',
            workOrdinal: 2,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 4,
            conversationIn: 'convo-global-recov',
        );
        $this->assertSame('send_now', $claim2['disposition']);
        $query2Ref = $claim2['query_ref'];

        $query2 = LessonQuery::find($query2Ref);
        $this->assertSame(1, $query2->send_count);
        $this->assertSame('sending', $query2->state);
        $this->assertSame(5, $query2->expected_cursor_version);

        // Step 3: Simulate lost response - mark uncertain and stage W2 answer
        $validCombinedTextW2 = str_replace(
            ['W1 | The Number Line and Real Numbers', 'Lesson [1]'],
            ['W2 | Absolute Value and Distances', 'Lesson [2]'],
            $validCombinedTextW1
        );
        $answerShaW2 = hash('sha256', $validCombinedTextW2);
        $answerPathW2 = "answers/{$this->processingCase->id}/{$query2Ref}_{$answerShaW2}.txt";
        Storage::disk('v3_private')->put($answerPathW2, $validCombinedTextW2);

        $query2->update([
            'state' => 'uncertain',
            'error_code' => 'network_timeout',
            'answer_path' => $answerPathW2,
            'answer_sha256' => $answerShaW2,
            'answer_bytes' => strlen($validCombinedTextW2),
            'conversation_out' => 'convo-global-recov',
        ]);

        $bridgeMock = \Mockery::mock(BridgeClient::class);
        $bridgeMock->shouldReceive('notifyLessonReady')
            ->andReturn(['status' => 202, 'data' => ['accepted' => true]]);
        $this->app->instance(BridgeClient::class, $bridgeMock);

        // Step 4: Reconcile Work 2
        Artisan::call('v3:reconcile-notebook-protocol');

        // Verify Work 2 bundle committed and cursor finished
        $this->assertSame(2, NotebookLessonBundle::where('case_id', $this->processingCase->id)->count());
        $cursorFinal = LessonCursor::where('case_id', $this->processingCase->id)->first();
        $this->assertSame(6, $cursorFinal->version);
        $this->assertSame('complete', $cursorFinal->phase);

        // Verify send_count was never incremented again
        $this->assertSame(1, LessonQuery::find($query2Ref)->send_count);
    }

    public function test_n06_lost_divide_without_conversation_id_blocks_and_never_fabricates(): void
    {
        // Create an uncertain Divide query with no conversation_out
        $divideQuery = LessonQuery::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'map_id' => null,
            'binding_id' => $this->binding->id,
            'kind' => 'divide',
            'target_work_ordinal' => 0,
            'expected_cursor_version' => null,
            'state' => 'uncertain',
            'error_code' => 'network_timeout',
            'send_count' => 1,
            'request_sha256' => hash('sha256', 'req-divide-lost'),
            'conversation_in' => null,
            'conversation_out' => null,
            'sent_at' => now(),
        ]);

        Artisan::call('v3:reconcile-notebook-protocol');

        // Must remain uncertain, no conversation invented, send_count unchanged
        $fresh = LessonQuery::find($divideQuery->id);
        $this->assertSame('uncertain', $fresh->state);
        $this->assertNull($fresh->conversation_out);
        $this->assertSame(1, $fresh->send_count);
    }
}
