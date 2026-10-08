<?php

namespace Tests\Integration\V3\Review;

use App\Models\V3\ApprovedScene;
use App\Models\V3\AdminCommandDelivery;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewRoute;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\TestCase;

class ReviewEffectControlRaceTest extends TestCase
{
    private string $tempDb;
    private string $barrierPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_review_control_race_' . uniqid() . '.sqlite';
        touch($this->tempDb);
        $this->barrierPath = sys_get_temp_dir() . '/v3_review_dispatch_barrier_' . uniqid();

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA journal_mode = WAL;');
        DB::statement('PRAGMA busy_timeout = 10000;');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->barrierPath, $this->barrierPath . '.entered', $this->barrierPath . '.release', $this->barrierPath . '.second.entered', $this->barrierPath . '.second.release'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_file($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    public function test_cancel_committed_during_bridge_start_tracks_the_already_sending_correction(): void
    {
        $this->runControlRace('cancel', 'operator_investigation', 'cancel_requested');
    }

    public function test_hold_committed_during_bridge_start_tracks_the_already_sending_correction(): void
    {
        $this->runControlRace('hold', 'operator_investigation', 'hold_requested');
    }

    private function runControlRace(string $action, string $reason, string $expectedMode): void
    {
        $env = AuthoringFixtures::createAuthoringEnvironment();
        $case = $env['case'];
        $case->state = 'running';
        $case->state_version = 1;
        $case->save();

        $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
        $reviewId = (string) Str::uuid();
        TeacherReviewItem::forceCreate([
            'id' => $reviewId,
            'case_id' => $case->id,
            'section_id' => $qualified['section']->id,
            'candidate_id' => $qualified['candidate']->id,
            'qualification_id' => $qualified['qualification']->id,
            'prompt_version_id' => $qualified['candidate']->prompt_version_id,
            'candidate_version' => 1,
            'status' => 'decided',
            'ready_at' => now(),
        ]);
        $decisionId = (string) Str::uuid();
        TeacherReviewDecision::forceCreate([
            'id' => $decisionId,
            'review_item_id' => $reviewId,
            'case_id' => $case->id,
            'section_id' => $qualified['section']->id,
            'candidate_id' => $qualified['candidate']->id,
            'qualification_id' => $qualified['qualification']->id,
            'actor_user_id' => $env['teacher']->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => str_repeat('a', 64),
            'decision' => 'approve',
            'created_at' => now(),
        ]);
        $approvalId = (string) Str::uuid();
        ApprovedScene::forceCreate([
            'id' => $approvalId,
            'case_id' => $case->id,
            'section_id' => $qualified['section']->id,
            'candidate_id' => $qualified['candidate']->id,
            'qualification_id' => $qualified['qualification']->id,
            'review_decision_id' => $decisionId,
            'candidate_version' => 1,
            'approved_at' => now(),
        ]);

        $correctionId = (string) Str::uuid();
        PostapprovalCorrection::forceCreate([
            'id' => $correctionId,
            'case_id' => $case->id,
            'section_id' => $qualified['section']->id,
            'approval_id' => $approvalId,
            'actor_user_id' => $env['teacher']->id,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => str_repeat('b', 64),
            'feedback_kind' => 'visual_interaction',
            'note_path' => "review-notes/{$correctionId}.txt",
            'note_sha256' => hash('sha256', 'private teacher note'),
            'note_bytes' => 20,
            'state' => 'accepted',
            'created_at' => now(),
        ]);
        $correctionWorkflowId = "v3-review-correction-{$correctionId}";
        ReviewRoute::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'section_id' => $qualified['section']->id,
            'review_decision_id' => null,
            'postapproval_correction_id' => $correctionId,
            'kind' => 'scene_repair',
            'state' => 'pending',
            'target_ref' => null,
        ]);
        $outbox = ReviewOutbox::forceCreate([
            'id' => (string) Str::uuid(),
            'review_decision_id' => null,
            'postapproval_correction_id' => $correctionId,
            'operation' => 'start_correction',
            'workflow_id' => $correctionWorkflowId,
            'state' => 'pending',
            'attempt_count' => 0,
        ]);
        $caseWorkflowId = "v3-case-{$case->id}";
        WorkflowLink::forceCreate([
            'case_id' => $case->id,
            'workflow_id' => $caseWorkflowId,
            'run_id' => 'run-control-race',
            'settings_version_id' => $env['settingsVersion']->id,
            'stage_code' => 'teacher_review',
            'status_code' => 'running',
            'attempt_count' => 1,
            'scene_count' => 1,
            'approved_scene_count' => 1,
            'start_state' => 'started',
            'started_at' => now(),
            'last_event_at' => now(),
        ]);

        $worker = new Process([
            PHP_BINARY,
            base_path('tests/Support/V3/ConcurrentReviewDispatchWorker.php'),
            '--db=' . $this->tempDb,
            '--barrier=' . $this->barrierPath,
        ]);
        $worker->start();

        $deadline = microtime(true) + 10;
        while (! is_file($this->barrierPath . '.entered') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->barrierPath . '.entered', 'Dispatcher never reached the bridge after claiming the outbox row.');

        $outbox->refresh();
        $this->assertSame('sending', $outbox->state);
        $this->assertSame(1, $outbox->attempt_count);

        // A second real dispatcher process must respect the unexpired lease and
        // must not create a second bridge start while the first call is in flight.
        $duplicateDispatcher = new Process([
            PHP_BINARY,
            base_path('tests/Support/V3/ConcurrentReviewDispatchWorker.php'),
            '--db=' . $this->tempDb,
            '--barrier=' . $this->barrierPath . '.second',
        ]);
        $duplicateDispatcher->start();
        $duplicateDispatcher->wait();
        $this->assertSame(0, $duplicateDispatcher->getExitCode(), $duplicateDispatcher->getOutput() . $duplicateDispatcher->getErrorOutput());
        $this->assertSame(['success' => true, 'processed' => 0], json_decode($duplicateDispatcher->getOutput(), true));
        $this->assertFileDoesNotExist($this->barrierPath . '.second.entered', 'An active lease must prevent a duplicate bridge start.');

        $response = $this->actingAs($env['admin'])->postJson("/v3/admin/operations/{$case->id}/commands", [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 1,
            'expected_control_version' => 0,
            'action' => $action,
            'reason_code' => $reason,
            'target_kind' => null,
            'target_ref' => null,
        ]);
        $response->assertCreated();
        $this->assertSame($expectedMode, $response->json('data.control_mode'));

        $deliveries = AdminCommandDelivery::where('command_id', $response->json('data.command_ref'))->get();
        $this->assertEqualsCanonicalizing(
            [$caseWorkflowId, $correctionWorkflowId],
            $deliveries->pluck('workflow_id')->all(),
            'The control command must include the correction that crossed the send boundary before the control commit.'
        );
        $this->assertSame('running', $case->fresh()->state, 'The remote control remains requested until signed workflow proof arrives.');

        touch($this->barrierPath . '.release');
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getOutput() . $worker->getErrorOutput());
        $this->assertSame(['success' => true, 'processed' => 1], json_decode($worker->getOutput(), true));

        $outbox->refresh();
        $this->assertSame('delivered', $outbox->state);
        $this->assertSame(1, $outbox->attempt_count);
        $this->assertSame($expectedMode, CaseOperationControl::where('case_id', $case->id)->value('mode'));
    }
}
