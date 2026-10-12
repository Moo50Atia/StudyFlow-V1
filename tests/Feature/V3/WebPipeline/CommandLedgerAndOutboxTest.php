<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineEvent;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\SubjectRun;
use App\Services\V3\WebPipeline\CommandConflictException;
use App\Services\V3\WebPipeline\CommandLedger;
use App\Services\V3\WebPipeline\RunEventStore;
use App\Services\V3\WebPipeline\RunOutbox;
use App\Services\V3\WebPipeline\StaleVersionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class CommandLedgerAndOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected SubjectRun $run;
    protected CommandLedger $ledger;
    protected RunOutbox $outbox;
    protected RunEventStore $eventStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = WebPipelineFixtures::createTeacherA();
        $env = AuthoringFixtures::createAuthoringEnvironment();
        $case = $env['case'];

        $frameworkId = (string) Str::uuid();
        NotebookFrameworkVersion::create([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/test.txt',
            'body_sha256' => hash('sha256', 'framework body'),
            'body_bytes' => 100,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->teacher->id,
            'approved_at' => now(),
        ]);

        $subject = Subject::create([
            'name' => 'Ledger Testing',
            'description' => 'Command ledger test suite',
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'origin' => 'web_intake',
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $subject->id,
            'settings_version_id' => $case->settings_version_id,
            'framework_version_id' => $frameworkId,
            'captured_settings_json' => ['mode' => 'export_manual'],
            'capture_sha256' => hash('sha256', 'settings'),
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'request'),
            'state_version' => 1,
        ]);

        $this->ledger = app(CommandLedger::class);
        $this->outbox = app(RunOutbox::class);
        $this->eventStore = app(RunEventStore::class);
    }

    public function test_compute_digest_is_canonical_and_key_order_independent(): void
    {
        $payload1 = ['b' => 2, 'a' => 1, 'nested' => ['z' => 26, 'y' => 25]];
        $payload2 = ['nested' => ['y' => 25, 'z' => 26], 'a' => 1, 'b' => 2];

        $digest1 = $this->ledger->computeDigest($payload1);
        $digest2 = $this->ledger->computeDigest($payload2);

        $this->assertSame($digest1, $digest2);
        $this->assertSame(64, strlen($digest1));
    }

    public function test_record_command_creates_accepted_command(): void
    {
        $commandId = (string) Str::uuid();
        $payload = ['action' => 'pause', 'expected_version' => 1];

        $result = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: $payload,
            commandId: $commandId
        );

        $this->assertSame('created', $result['status']);
        $this->assertSame(1, $result['state_version']);
        $this->assertInstanceOf(PipelineCommand::class, $result['command']);
        $this->assertSame('accepted', $result['command']->state);
        $this->assertDatabaseHas('v3_pipeline_commands', [
            'id' => $commandId,
            'run_id' => $this->run->id,
            'action' => 'pause',
            'state' => 'accepted',
        ]);
    }

    public function test_record_command_duplicate_returns_prior_receipt(): void
    {
        $commandId = (string) Str::uuid();
        $payload = ['action' => 'pause', 'expected_version' => 1];

        $res1 = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: $payload,
            commandId: $commandId
        );

        $res2 = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: $payload,
            commandId: $commandId
        );

        $this->assertSame('created', $res1['status']);
        $this->assertSame('duplicate', $res2['status']);
        $this->assertSame($res1['command']->id, $res2['command']->id);
        $this->assertSame(1, PipelineCommand::where('id', $commandId)->count());
    }

    public function test_record_command_with_differing_digest_throws_conflict(): void
    {
        $commandId = (string) Str::uuid();

        $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: ['param' => 'initial'],
            commandId: $commandId
        );

        $this->expectException(CommandConflictException::class);
        $this->expectExceptionCode(409);

        $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: ['param' => 'changed_payload'],
            commandId: $commandId
        );
    }

    public function test_record_command_with_stale_expected_version_throws_conflict(): void
    {
        $this->run->update(['state_version' => 3]);

        $this->expectException(StaleVersionException::class);
        $this->expectExceptionCode(409);

        $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 2, // stale, actual is 3
            payload: ['action' => 'pause']
        );
    }

    public function test_apply_command_advances_run_version_and_marks_applied(): void
    {
        $res = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: ['action' => 'pause']
        );

        $applied = $this->ledger->applyCommand($res['command'], 'ok', 'result-ref-123');

        $this->assertSame('applied', $applied->state);
        $this->assertNotNull($applied->applied_at);
        $this->assertSame('ok', $applied->safe_code);
        $this->assertSame('result-ref-123', $applied->result_ref);

        $this->run->refresh();
        $this->assertSame(2, $this->run->state_version);
    }

    public function test_outbox_enqueue_claim_and_deliver(): void
    {
        $res = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: ['action' => 'pause']
        );

        $outbox = $this->outbox->enqueue($res['command']);
        $this->assertSame('pending', $outbox->state);
        $this->assertSame($res['command']->id, $outbox->command_id);

        // Claim pending
        $claimed = $this->outbox->claimPending(limit: 5, leaseSeconds: 30);
        $this->assertCount(1, $claimed);
        $item = $claimed->first();
        $this->assertSame('leased', $item->state);
        $this->assertNotNull($item->lease_token);
        $this->assertSame(1, $item->attempt_count);

        // Deliver with wrong lease token fails
        $wrongDelivered = $this->outbox->markDelivered($item->id, 'wrong-lease-token');
        $this->assertFalse($wrongDelivered);

        // Deliver with correct lease token succeeds
        $delivered = $this->outbox->markDelivered($item->id, $item->lease_token, 'ok');
        $this->assertTrue($delivered);

        $item->refresh();
        $this->assertSame('delivered', $item->state);
        $this->assertNull($item->lease_token);
    }

    public function test_outbox_mark_failed_and_retry_backoff(): void
    {
        $res = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: ['action' => 'pause']
        );

        $this->outbox->enqueue($res['command']);
        $claimed = $this->outbox->claimPending(limit: 5, leaseSeconds: 30);
        $item = $claimed->first();

        $this->outbox->markFailed($item->id, $item->lease_token, 'delivery_uncertain', retryDelaySeconds: 60, maxAttempts: 3);

        $item->refresh();
        $this->assertSame('pending', $item->state);
        $this->assertSame('delivery_uncertain', $item->safe_code);
        $this->assertNotNull($item->next_attempt_at);
        $this->assertNull($item->lease_token);

        // Subsequent failure exceeding max attempts marks terminal failed
        $item->update(['attempt_count' => 3]);
        $item->update(['state' => 'leased', 'lease_token' => 'token-3']);

        $this->outbox->markFailed($item->id, 'token-3', 'storage_unavailable', maxAttempts: 3);
        $item->refresh();
        $this->assertSame('failed', $item->state);
    }

    public function test_outbox_reconciles_lost_ack_when_command_applied(): void
    {
        $res = $this->ledger->recordCommand(
            runId: $this->run->id,
            actorUserId: $this->teacher->id,
            action: 'pause',
            expectedVersion: 1,
            payload: ['action' => 'pause']
        );

        $outbox = $this->outbox->enqueue($res['command']);
        $this->assertSame('pending', $outbox->state);

        // Simulate command applied durably
        $this->ledger->applyCommand($res['command']);

        $reconciled = $this->outbox->reconcileLostAck($res['command']->id);
        $this->assertNotNull($reconciled);
        $this->assertSame('delivered', $reconciled->state);
    }

    public function test_event_store_monotonically_sequences_events_per_run(): void
    {
        $e1 = $this->eventStore->recordEvent($this->run->id, [
            'kind' => 'command_accepted',
            'phase' => 'staging',
            'outcome' => 'pending',
            'safe_code' => 'ok',
        ]);

        $e2 = $this->eventStore->recordEvent($this->run->id, [
            'kind' => 'import_started',
            'phase' => 'intake',
            'outcome' => 'pending',
            'safe_code' => 'ok',
        ]);

        $this->assertSame(1, $e1->sequence);
        $this->assertSame(2, $e2->sequence);

        $events = $this->eventStore->getEvents($this->run->id, afterSequence: 0);
        $this->assertCount(2, $events);
        $this->assertSame(['command_accepted', 'import_started'], $events->pluck('kind')->all());
    }

    public function test_event_store_sanitizes_private_fields_and_validates_counts(): void
    {
        $event = $this->eventStore->recordEvent($this->run->id, [
            'kind' => 'scenes_checked',
            'phase' => 'generation',
            'outcome' => 'completed',
            'safe_code' => 'ok',
            'body' => 'SENSITIVE LECTURE BODY MUST BE STRIPPED',
            'prompt' => 'SENSITIVE PROMPT MUST BE STRIPPED',
            'secret' => 'SECRET_TOKEN_123',
            'counts' => [
                'scenes_total' => 5,
                'scenes_built' => '3',
                'invalid_body' => 'not an int',
            ],
        ]);

        $this->assertDatabaseHas('v3_pipeline_events', [
            'id' => $event->id,
            'kind' => 'scenes_checked',
            'safe_code' => 'ok',
        ]);

        $saved = PipelineEvent::find($event->id);
        $this->assertSame(['scenes_total' => 5, 'scenes_built' => 3], $saved->counts_json);
        $this->assertArrayNotHasKey('body', (array) $saved->getAttributes());
        $this->assertArrayNotHasKey('prompt', (array) $saved->getAttributes());
    }
}
