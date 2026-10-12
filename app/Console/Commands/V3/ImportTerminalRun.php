<?php

namespace App\Console\Commands\V3;

use App\Services\V3\WebPipeline\CommandConflictException;
use App\Services\V3\WebPipeline\MaterializeTerminalImport;
use App\Services\V3\WebPipeline\TerminalArchiveReader;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportTerminalRun extends Command
{
    protected $signature = 'v3:import-terminal-run
        {--terminal-root= : Absolute path to terminal root}
        {--run-id= : Source run UUID}
        {--subject= : Destination subject ID}
        {--teacher= : Destination teacher ID}
        {--actor= : Administrator user ID performing import}
        {--import-id= : Unique import operation UUID}
        {--dry-run : Validate and plan without writing mutations}
        {--queue : Queue import via durable outbox and Temporal supervisor}';

    protected $description = 'Import verified historical terminal run archive into teacher subject.';

    public function handle(
        TerminalArchiveReader $reader,
        MaterializeTerminalImport $materializer
    ): int {
        $terminalRoot = $this->option('terminal-root');
        $runId = $this->option('run-id');
        $subjectId = $this->option('subject');
        $teacherId = $this->option('teacher');
        $actorId = $this->option('actor');
        $importId = $this->option('import-id') ?: (string) Str::uuid();
        $isDryRun = (bool) $this->option('dry-run');

        if (! $terminalRoot || ! $runId || ! $subjectId || ! $teacherId || ! $actorId) {
            $this->outputResult([
                'state' => 'failed',
                'safe_code' => 'receipt_invalid',
                'error' => 'Missing required arguments: terminal-root, run-id, subject, teacher, and actor are mandatory.',
            ]);
            return 2;
        }

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: (string) $terminalRoot,
            runId: (string) $runId,
            subjectId: (int) $subjectId,
            teacherId: (int) $teacherId,
            actorAdminId: (int) $actorId,
            importId: (string) $importId,
            isDryRun: $isDryRun
        );

        if (! $plan['is_valid']) {
            $safeCode = $plan['safe_code'];
            $exitCode = in_array($safeCode, ['authentication_required', 'account_mismatch'], true) ? 2 : 1;

            $this->outputResult([
                'import_id' => $importId,
                'run_id' => $runId,
                'state' => 'failed',
                'safe_code' => $safeCode,
                'errors' => $plan['errors'],
            ]);
            return $exitCode;
        }

        if ($isDryRun) {
            $this->outputResult([
                'import_id' => $importId,
                'run_id' => $runId,
                'state' => 'validated',
                'safe_code' => 'ok',
                'is_dry_run' => true,
                'pdf_count' => $plan['pdf_count'],
            ]);
            return 0;
        }

        if ($this->option('queue')) {
            $outbox = app(\App\Services\V3\WebPipeline\RunOutbox::class);
            $existingCommand = \App\Models\V3\PipelineCommand::find($importId);
            if ($existingCommand && $existingCommand->request_sha256 !== $plan['capture']->digest) {
                $this->outputResult([
                    'import_id' => $importId,
                    'run_id' => $runId,
                    'state' => 'conflict',
                    'safe_code' => 'import_conflict',
                ]);
                return 1;
            }

            if ($existingCommand) {
                $outbox->enqueue($existingCommand);
                $this->outputResult([
                    'import_id' => $importId,
                    'run_id' => $runId,
                    'state' => 'queued',
                    'safe_code' => 'ok',
                    'is_dry_run' => false,
                    'pdf_count' => $plan['pdf_count'],
                ]);
                return 3;
            }

            // Persist only bounded, opaque import metadata. The worker resolves
            // this private manifest through the signed Laravel API; Laravel
            // re-verifies the source archive immediately before materializing.
            $manifestRef = (string) Str::uuid();
            $manifest = [
                'schema_version' => 1,
                'kind' => 'web_import_v1',
                'manifest_ref' => $manifestRef,
                'run_id' => $runId,
                'source_run_id' => $runId,
                'import_id' => $importId,
                'import_digest' => $plan['capture']->digest,
                'terminal_root' => (string) $terminalRoot,
                'subject_id' => (int) $subjectId,
                'teacher_id' => (int) $teacherId,
                'actor_admin_id' => (int) $actorId,
                'created_at' => now()->toIso8601String(),
            ];
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($manifestJson) > 32768) {
                $this->outputResult([
                    'import_id' => $importId,
                    'run_id' => $runId,
                    'state' => 'failed',
                    'safe_code' => 'contract_invalid',
                    'error' => 'Import manifest exceeds the metadata limit.',
                ]);
                return 2;
            }
            \Illuminate\Support\Facades\Storage::disk('v3_private')->put("manifests/{$manifestRef}.json", $manifestJson);

            $settingsVersionId = \Illuminate\Support\Facades\DB::table('v3_settings_versions')->max('id');
            if (! $settingsVersionId) {
                $settingsVersionId = \Illuminate\Support\Facades\DB::table('v3_settings_versions')->insertGetId([
                    'version' => 1,
                    'schema_version' => 1,
                    'payload' => json_encode(['import' => true]),
                    'checksum' => hash('sha256', 'import'),
                    'published_by_user_id' => (int) $actorId,
                    'published_at' => now(),
                ]);
            }

            $frameworkId = \Illuminate\Support\Facades\DB::table('v3_notebook_framework_versions')->value('id');
            if (! $frameworkId) {
                $frameworkId = (string) Str::uuid();
                \Illuminate\Support\Facades\DB::table('v3_notebook_framework_versions')->insert([
                    'id' => $frameworkId,
                    'profile' => 'expanded-root-v1',
                    'body_path' => 'frameworks/import_default.txt',
                    'body_sha256' => hash('sha256', 'import_default'),
                    'body_bytes' => 100,
                    'first_sha256' => hash('sha256', 'first'),
                    'second_sha256' => hash('sha256', 'second'),
                    'third_sha256' => hash('sha256', 'third'),
                    'response_format_sha256' => hash('sha256', 'format'),
                    'approved_by_user_id' => (int) $actorId,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);
            }

            \App\Models\V3\SubjectRun::firstOrCreate(
                ['id' => $runId],
                [
                    'origin' => 'historical_import',
                    'owner_user_id' => (int) $teacherId,
                    'subject_id' => (int) $subjectId,
                    'protocol' => 'notebooklm_per_pdf_web_v1',
                    'settings_version_id' => $settingsVersionId,
                    'framework_version_id' => $frameworkId,
                    'mode' => 'export_manual',
                    'qualification_mode' => 'owner_waived_demo',
                    'captured_settings_json' => $plan['capture']->subjectConfig,
                    'capture_sha256' => hash('sha256', json_encode($plan['capture']->subjectConfig)),
                    'submission_key' => (string) Str::uuid(),
                    'request_sha256' => $plan['capture']->digest,
                    'state' => 'queued',
                    'state_version' => 1,
                    'total_pdfs' => count($plan['capture']->pdfArchives),
                    'total_lessons' => 0,
                    'total_scenes' => count($plan['capture']->scenePrompts),
                ]
            );

            $command = \App\Models\V3\PipelineCommand::firstOrCreate(
                ['id' => $importId],
                [
                    'run_id' => $runId,
                    'actor_user_id' => (int) $actorId,
                    'action' => 'import_terminal_run',
                    'request_sha256' => $plan['capture']->digest,
                    'expected_version' => 1,
                    'private_payload_ref' => $manifestRef,
                    'state' => 'pending',
                ]
            );

            $outbox->enqueue($command);

            $this->outputResult([
                'import_id' => $importId,
                'run_id' => $runId,
                'state' => 'queued',
                'safe_code' => 'ok',
                'is_dry_run' => false,
                'pdf_count' => $plan['pdf_count'],
            ]);
            return 3;
        }

        try {
            $res = $materializer->materialize($plan['capture']);
            $isPartial = $plan['capture']->isPartial();
            $state = $res['status'] === 'duplicate' ? 'duplicate' : ($isPartial ? 'partial' : 'completed');
            $this->outputResult([
                'import_id' => $importId,
                'run_id' => $runId,
                'state' => $state,
                'safe_code' => 'ok',
                'is_dry_run' => false,
                'pdf_count' => $plan['pdf_count'],
                'status' => $res['status'],
            ]);
            return $isPartial ? 1 : 0;
        } catch (CommandConflictException $e) {
            $this->outputResult([
                'import_id' => $importId,
                'run_id' => $runId,
                'state' => 'conflict',
                'safe_code' => $e->getSafeCode(),
                'error' => $e->getMessage(),
            ]);
            return 1;
        } catch (\Throwable $e) {
            $this->outputResult([
                'import_id' => $importId,
                'run_id' => $runId,
                'state' => 'failed',
                'safe_code' => 'storage_unavailable',
                'error' => 'Materialization failed',
            ]);
            return 1;
        }
    }

    protected function outputResult(array $data): void
    {
        $this->line(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
