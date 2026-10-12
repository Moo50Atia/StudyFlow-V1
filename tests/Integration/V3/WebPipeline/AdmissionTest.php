<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\SubjectRun;
use App\Services\V3\WebPipeline\DispatchRunOutbox;
use App\Services\V3\WebPipeline\TerminalBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class AdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected Subject $subject;
    protected SubjectRun $run;
    protected PipelineCommand $command;
    protected PipelineOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.v3.bridge_url' => 'http://127.0.0.1:8765']);
        config(['services.v3.bridge_hmac_key' => 'test-integration-secret-key']);

        $admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => '{}',
            'checksum' => hash('sha256', '{}'),
            'published_by_user_id' => $admin->id,
            'published_at' => now(),
        ]);

        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', 'mock_framework'),
            'body_bytes' => 14,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'fmt'),
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $settingsId,
            'framework_version_id' => $frameworkId,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req1'),
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'state' => 'staging',
            'state_version' => 1,
            'total_pdfs' => 1,
            'last_event_sequence' => 0,
        ]);

        \App\Models\V3\SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Sample PDF',
            'original_filename' => 'sample.pdf',
            'input_sha256' => hash('sha256', 'pdf_data'),
            'input_bytes' => 1024,
            'state' => 'staging',
            'state_version' => 1,
        ]);

        $manifestService = app(\App\Services\V3\WebPipeline\CaptureSubjectRunManifest::class);
        $captured = $manifestService->capture($this->run);

        $this->command = PipelineCommand::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'submit',
            'request_sha256' => hash('sha256', 'cmd1'),
            'expected_version' => 1,
            'private_payload_ref' => $captured['manifest_ref'],
            'state' => 'accepted',
        ]);

        $this->outbox = PipelineOutbox::create([
            'id' => (string) Str::uuid(),
            'command_id' => $this->command->id,
            'destination' => 'application_bridge',
            'state' => 'pending',
            'attempt_count' => 0,
        ]);
    }

    public function test_capture_and_resolve_manifest(): void
    {
        $service = app(\App\Services\V3\WebPipeline\CaptureSubjectRunManifest::class);
        $captured = $service->capture($this->run);

        $this->assertNotEmpty($captured['manifest_ref']);
        $this->assertSame(64, strlen($captured['manifest_sha256']));
        $this->assertCount(1, $captured['manifest']['items']);

        // Test resolve
        $resolved = $service->resolve($captured['manifest_ref']);
        $this->assertSame($this->run->id, $resolved['run_id']);

        // Missing manifest -> 404
        try {
            $service->resolve((string) \Illuminate\Support\Str::uuid());
            $this->fail('Expected 404');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Invalid UUID -> 422
        try {
            $service->resolve('invalid-uuid');
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_bridge_client_signs_exact_bytes_and_headers(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/*' => Http::response([
                'outcome' => 'accepted',
                'submission_id' => $this->run->submission_key,
            ], 202),
        ]);

        $client = app(TerminalBridgeClient::class);
        $payload = [
            'submission_id' => $this->run->submission_key,
            'submission_digest' => hash('sha256', 'digest1'),
            'run_id' => $this->run->id,
            'manifest_ref' => (string) Str::uuid(),
            'workflow_kind' => 'web_subject_v1',
        ];

        $resp = $client->submitAdmission($payload);
        $this->assertSame('accepted', $resp['outcome']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-V3-Timestamp')
                && $request->hasHeader('X-V3-Signature')
                && $request->header('X-V3-Contract-Version')[0] === '1'
                && str_contains($request->url(), '/internal/v1/application-pipeline/submissions');
        });
    }

    public function test_dispatch_run_outbox_advances_to_acknowledged(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/submissions' => Http::response([
                'outcome' => 'accepted',
                'submission_id' => $this->run->submission_key,
            ], 202),
        ]);

        $dispatcher = app(DispatchRunOutbox::class);
        $dispatched = $dispatcher->dispatchPending();

        $this->assertSame(1, $dispatched);
        $this->outbox->refresh();
        $this->assertSame('acknowledged', $this->outbox->state);
        $this->assertSame(1, $this->outbox->attempt_count);
    }

    public function test_scene_authoring_outbox_dispatches_opaque_job_to_supervisor(): void
    {
        $authoringRequestRef = (string) Str::uuid();
        $manifestRef = (string) Str::uuid();
        $command = PipelineCommand::create([
            'id' => $authoringRequestRef,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'generate_scene',
            'request_sha256' => hash('sha256', 'authoring-request'),
            'expected_version' => 1,
            'private_payload_ref' => $manifestRef,
            'state' => 'pending',
        ]);
        $outbox = PipelineOutbox::create([
            'id' => (string) Str::uuid(),
            'command_id' => $command->id,
            'destination' => 'application_bridge',
            'state' => 'pending',
            'attempt_count' => 0,
        ]);

        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/submissions' => Http::response([
                'outcome' => 'accepted',
                'submission_id' => $command->id,
                'subject_count' => 1,
            ], 202),
        ]);

        $this->assertTrue(app(DispatchRunOutbox::class)->dispatchItem($outbox->fresh()));

        Http::assertSent(function ($request) use ($command, $manifestRef) {
            $body = $request->data();
            return str_ends_with($request->url(), '/internal/v1/application-pipeline/submissions')
                && ($body['workflow_kind'] ?? null) === 'web_authoring_v1'
                && ($body['run_id'] ?? null) === $this->run->id
                && ($body['subject_id'] ?? null) === $command->id
                && ($body['manifest_ref'] ?? null) === $manifestRef;
        });
    }

    public function test_queued_terminal_import_is_admitted_as_import_workflow(): void
    {
        $importId = (string) Str::uuid();
        $manifestRef = (string) Str::uuid();
        $command = PipelineCommand::create([
            'id' => $importId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'import_terminal_run',
            'request_sha256' => hash('sha256', 'archive-import'),
            'expected_version' => 1,
            'private_payload_ref' => $manifestRef,
            'state' => 'pending',
        ]);
        $outbox = PipelineOutbox::create([
            'id' => (string) Str::uuid(),
            'command_id' => $command->id,
            'destination' => 'application_bridge',
            'state' => 'pending',
            'attempt_count' => 0,
        ]);

        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/submissions' => Http::response([
                'outcome' => 'accepted',
                'submission_id' => $command->id,
                'subject_count' => 1,
            ], 202),
        ]);

        $this->assertTrue(app(DispatchRunOutbox::class)->dispatchItem($outbox->fresh()));
        Http::assertSent(function ($request) use ($command, $manifestRef) {
            $body = $request->data();
            return str_ends_with($request->url(), '/internal/v1/application-pipeline/submissions')
                && ($body['workflow_kind'] ?? null) === 'web_import_v1'
                && ($body['run_id'] ?? null) === $this->run->id
                && ($body['subject_id'] ?? null) === $command->id
                && ($body['manifest_ref'] ?? null) === $manifestRef;
        });
    }

    public function test_ack_loss_reconciliation_retrieves_prior_receipt(): void
    {
        // First attempt fails with 503 / network timeout
        Http::fakeSequence()
            ->push(['error' => ['code' => 'service_unavailable']], 503)
            ->push([
                'outcome' => 'accepted',
                'submission_id' => $this->run->submission_key,
            ], 200);

        $dispatcher = app(DispatchRunOutbox::class);

        // Attempt 1 fails
        $dispatcher->dispatchPending();
        $this->outbox->refresh();
        $this->assertSame('pending', $this->outbox->state);
        $this->assertSame(1, $this->outbox->attempt_count);

        // Advance retry time
        $this->outbox->update(['next_attempt_at' => now()->subMinute()]);

        // Attempt 2 succeeds
        $dispatcher->dispatchPending();
        $this->outbox->refresh();
        $this->assertSame('acknowledged', $this->outbox->state);
        $this->assertSame(2, $this->outbox->attempt_count);
    }

    public function test_bridge_digest_conflict_marks_outbox_failed_with_operation_conflict(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/submissions' => Http::response([
                'error' => [
                    'code' => 'operation_conflict',
                    'message' => 'Digest mismatch for submission',
                ],
            ], 409),
        ]);

        $dispatcher = app(DispatchRunOutbox::class);
        $dispatcher->dispatchPending();

        $this->outbox->refresh();
        $this->assertSame('failed', $this->outbox->state);
        $this->assertSame('operation_conflict', $this->outbox->safe_code);
    }

    public function test_admission_rejects_non_uuid_manifest_ref(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/submissions' => Http::response([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid UUID for manifest_ref',
                ],
            ], 422),
        ]);

        $client = app(TerminalBridgeClient::class);
        $payload = [
            'submission_id' => $this->run->submission_key,
            'submission_digest' => hash('sha256', 'digest1'),
            'run_id' => $this->run->id,
            'manifest_ref' => 'not-a-valid-uuid',
            'workflow_kind' => 'web_subject_v1',
        ];

        try {
            $client->submitAdmission($payload);
            $this->fail('Expected 422 HttpException');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_admission_rejects_payload_above_32_kib(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/submissions' => Http::response([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Encoded payload exceeds 32 KiB ceiling',
                ],
            ], 422),
        ]);

        $client = app(TerminalBridgeClient::class);
        $payload = [
            'submission_id' => $this->run->submission_key,
            'submission_digest' => hash('sha256', 'digest1'),
            'run_id' => $this->run->id,
            'manifest_ref' => (string) Str::uuid(),
            'workflow_kind' => 'web_subject_v1',
            'large_pad' => str_repeat('x', 33000),
        ];

        try {
            $client->submitAdmission($payload);
            $this->fail('Expected 422 HttpException');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_admission_rejects_unsupported_command_action(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/commands' => Http::response([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Unsupported action: invalid_action',
                ],
            ], 422),
        ]);

        $client = app(TerminalBridgeClient::class);
        $payload = [
            'command_id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'action' => 'invalid_action',
            'expected_version' => 1,
            'command_digest' => hash('sha256', 'cmd_invalid'),
        ];

        try {
            $client->sendCommand($payload);
            $this->fail('Expected 422 HttpException');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_dispatch_outbox_reuses_persisted_manifest_ref(): void
    {
        $persistedManifestRef = (string) Str::uuid();
        $this->command->update(['private_payload_ref' => $persistedManifestRef]);

        $sentManifestRefs = [];
        Http::fake(function ($request) use (&$sentManifestRefs) {
            if (str_contains($request->url(), '/submissions')) {
                $data = $request->data();
                $sentManifestRefs[] = $data['manifest_ref'] ?? null;
                return Http::response([
                    'outcome' => 'accepted',
                    'submission_id' => $data['submission_id'] ?? '',
                ], 202);
            }
            return Http::response([], 404);
        });

        $dispatcher = app(DispatchRunOutbox::class);
        $dispatcher->dispatchPending();

        $this->assertNotEmpty($sentManifestRefs);
        $this->assertSame($persistedManifestRef, $sentManifestRefs[0]);
    }

    public function test_canonical_digest_changes_with_settings_or_qualification(): void
    {
        $baseData = [
            'submission_key' => 'sub1',
            'subject_id' => 10,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'file_shas' => ['sha1'],
            'titles' => ['Title 1'],
        ];
        $digest1 = hash('sha256', json_encode($baseData));

        $changedData = $baseData;
        $changedData['qualification_mode'] = 'real_jev';
        $digest2 = hash('sha256', json_encode($changedData));

        $this->assertNotSame($digest1, $digest2);
    }
}

