<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\NotebookInstance;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\PrivatePdfStore;
use App\Services\V3\Settings\SelectCaseSettings;
use App\Services\V3\Settings\SettingsUnavailable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubmitSubjectRun
{
    public function __construct(
        protected PrivatePdfStore $store,
        protected SelectCaseSettings $settingsSelector
    ) {}

    /**
     * Submit an ordered batch of PDFs for a subject run.
     *
     * @param User $actor
     * @param int|string $subjectId
     * @param array $payload
     * @return array{run_id: string, state: string, state_version: int}
     */
    public function submit(User $actor, int|string $subjectId, array $payload): array
    {
        // 1. Authorize actor
        $freshUser = DB::table('users')->where('id', $actor->id)->first();
        $access = DB::table('v3_account_access')->where('user_id', $actor->id)->first();

        if (! $freshUser || $freshUser->role !== 'teacher' || ! $access || ! (bool) $access->is_active) {
            throw new HttpException(403, 'V3 access is unavailable for this teacher.');
        }

        // Authorize permission on subject
        $hasPermission = DB::table('teacher_permissions')
            ->where('teacher_id', $actor->id)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $hasPermission) {
            throw new HttpException(403, 'Teacher does not have permission for this subject.');
        }

        // 2. Validate parameters
        $submissionKey = $payload['submission_key'] ?? null;
        if (! $submissionKey || ! is_string($submissionKey) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $submissionKey)) {
            throw new HttpException(422, 'Invalid or missing submission_key UUID.');
        }

        $files = $payload['files'] ?? [];
        if (! is_array($files) || count($files) < 1 || count($files) > 20) {
            throw new HttpException(422, 'Files must be an array of 1 to 20 uploaded PDFs.');
        }

        $titles = $payload['titles'] ?? [];
        if (! is_array($titles) || count($titles) !== count($files)) {
            throw new HttpException(422, 'Titles array length must match files array length.');
        }

        $mode = $payload['mode'] ?? 'export_manual';
        if (! in_array($mode, ['export_manual', 'api_generate'], true)) {
            throw new HttpException(422, 'Invalid processing mode.');
        }
        if ($mode !== 'export_manual' && ! (bool) config('services.v3_teacher_paid_scenes_enabled', false)) {
            throw new HttpException(403, 'Paid scene generation is unavailable for teachers.');
        }

        $qualificationMode = $payload['qualification_mode'] ?? 'real_jev';
        if ($qualificationMode === 'owner_waived_demo' && ! (bool) config('services.v3_teacher_demo_waiver_enabled', false)) throw new HttpException(403, 'Only the platform owner may waive qualification.');
        if (! in_array($qualificationMode, ['owner_waived_demo', 'real_jev'], true)) {
            throw new HttpException(422, 'Invalid qualification_mode.');
        }

        if ($qualificationMode === 'owner_waived_demo') {
            $acknowledged = filter_var($payload['waiver_acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $acknowledged) {
                throw new HttpException(422, 'Demo qualification requires explicit waiver_acknowledged.');
            }
        }

        // Check duplicate files in batch
        $fileShas = [];
        $fileContents = [];
        foreach ($files as $idx => $f) {
            if (! ($f instanceof UploadedFile) || ! $f->isValid()) {
                throw new HttpException(422, "Invalid upload for file at index {$idx}.");
            }
            $content = file_get_contents($f->getRealPath());
            $sha = hash('sha256', $content);
            if (in_array($sha, $fileShas, true)) {
                // Batch duplicate detected!
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'error' => [
                        'code' => 'duplicate_batch_pdf',
                        'message' => 'Duplicate PDF files detected in submission batch.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store'));
            }
            $fileShas[] = $sha;
            $fileContents[] = $content;
        }

        // 3. Compute canonical request digest
        $canonicalData = [
            'submission_key' => strtolower($submissionKey),
            'subject_id' => (int) $subjectId,
            'mode' => $mode,
            'qualification_mode' => $qualificationMode,
            'file_shas' => $fileShas,
            'titles' => array_values($titles),
        ];
        $requestSha256 = hash('sha256', json_encode($canonicalData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 4. Idempotency & Conflict Check
        $existing = SubjectRun::where('owner_user_id', $actor->id)
            ->where('submission_key', $submissionKey)
            ->first();

        if ($existing) {
            if ($existing->request_sha256 === $requestSha256) {
                return [
                    'run_id' => $existing->id,
                    'state' => $existing->state,
                    'state_version' => $existing->state_version,
                ];
            }

            abort(response()->json([
                'error' => [
                    'code' => 'submission_conflict',
                    'message' => 'Submission key already exists with differing payload.',
                ],
            ], 409));
        }

        // 5. Transaction: Capture settings, insert run, items, notebook, command, and outbox
        return DB::transaction(function () use (
            $actor,
            $subjectId,
            $submissionKey,
            $requestSha256,
            $mode,
            $qualificationMode,
            $files,
            $titles,
            $fileShas,
            $fileContents
        ) {
            try {
                $settings = $this->settingsSelector->selectForNewCase();
                $versionId = $settings->id;
                $payloadJson = is_array($settings->payload) ? json_encode($settings->payload) : $settings->payload;
                $checksum = $settings->checksum;
            } catch (SettingsUnavailable|\LogicException) {
                throw new HttpException(422, 'Published pipeline settings are unavailable.');
            }

            $frameworkId = DB::table('v3_notebook_framework_pointer')->where('id', 1)->value('version_id');
            $framework = $frameworkId
                ? DB::table('v3_notebook_framework_versions')->where('id', $frameworkId)->whereNotNull('approved_at')->first()
                : null;
            $frameworkBody = $framework && is_string($framework->body_path)
                && Storage::disk('v3_private')->exists($framework->body_path)
                ? Storage::disk('v3_private')->get($framework->body_path)
                : null;
            if (! $framework || ! is_string($frameworkBody)
                || ! hash_equals((string) $framework->body_sha256, hash('sha256', $frameworkBody))) {
                throw new HttpException(422, 'Approved notebook framework is unavailable or invalid.');
            }

            $runId = (string) Str::uuid();

            $run = SubjectRun::create([
                'id' => $runId,
                'owner_user_id' => $actor->id,
                'subject_id' => $subjectId,
                'origin' => 'web',
                'protocol' => 'notebooklm_per_pdf_web_v1',
                'settings_version_id' => $versionId,
                'framework_version_id' => $frameworkId,
                'mode' => $mode,
                'qualification_mode' => $qualificationMode,
                'captured_settings_json' => $payloadJson,
                'capture_sha256' => $checksum,
                'submission_key' => $submissionKey,
                'request_sha256' => $requestSha256,
                'state' => 'staging',
                'state_version' => 1,
                'total_pdfs' => count($files),
                'last_event_sequence' => 0,
                'namespace' => 'v3-terminal',
            ]);

            // Create items
            foreach ($files as $idx => $file) {
                $ordinal = $idx + 1;
                $attemptId = (string) Str::uuid();
                $content = $fileContents[$idx];
                $byteSize = $this->store->storeQuarantine($attemptId, $content);

                UploadAttempt::forceCreate([
                    'id' => $attemptId,
                    'owner_user_id' => $actor->id,
                    'upload_settings_version_id' => $versionId,
                    'state' => 'quarantined',
                    'staging_path' => $this->store->quarantinePath($attemptId),
                    'byte_size' => $byteSize,
                    'expires_at' => now()->addHours(24),
                ]);

                SubjectRunItem::create([
                    'id' => (string) Str::uuid(),
                    'run_id' => $run->id,
                    'owner_user_id' => $actor->id,
                    'pdf_ordinal' => $ordinal,
                    'display_title' => $titles[$idx],
                    'upload_attempt_id' => $attemptId,
                    'original_filename' => $file->getClientOriginalName(),
                    'input_sha256' => $fileShas[$idx],
                    'input_bytes' => $byteSize,
                    'state' => 'staging',
                    'state_version' => 1,
                ]);
            }

            // Create NotebookInstance
            NotebookInstance::create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'owner_user_id' => $actor->id,
                'adapter_kind' => 'notebooklm_py',
                'account_alias' => 'mo50atiapro',
                'state' => 'pending',
            ]);

            // Capture immutable manifest
            $manifestService = app(CaptureSubjectRunManifest::class);
            $captured = $manifestService->capture($run);

            // Create submit PipelineCommand
            $cmdId = (string) Str::uuid();
            $cmd = PipelineCommand::create([
                'id' => $cmdId,
                'run_id' => $run->id,
                'actor_user_id' => $actor->id,
                'action' => 'submit',
                'request_sha256' => $requestSha256,
                'expected_version' => 1,
                'private_payload_ref' => $captured['manifest_ref'],
                'state' => 'accepted',
                'accepted_at' => now(),
            ]);

            // Enqueue in PipelineOutbox for application_bridge
            PipelineOutbox::create([
                'id' => (string) Str::uuid(),
                'command_id' => $cmd->id,
                'destination' => 'application_bridge',
                'state' => 'pending',
                'attempt_count' => 0,
            ]);

            return [
                'run_id' => $run->id,
                'state' => 'staging',
                'state_version' => 1,
            ];
        });
    }
}
