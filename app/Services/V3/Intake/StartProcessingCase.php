<?php

namespace App\Services\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Settings\SelectCaseSettings;
use App\Services\V3\Settings\SettingsUnavailable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StartProcessingCase
{
    public function __construct(
        protected SelectCaseSettings $settingsSelector
    ) {}

    /**
     * Start or replay a processing case under strict lock order:
     * 004 access singleton -> owned Document -> 005 settings pointer -> case insert
     *
     * @return array{success: bool, status?: int, code?: string, message?: string, is_replay?: bool, case?: ProcessingCase}
     */
    public function start(int $actorUserId, string $documentId, string $action, string $idempotencyKey): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($actorUserId, $documentId, $action, $idempotencyKey) {
                    // 1. Lock 004 access control singleton
                    $lockAffected = DB::table('v3_access_control_locks')
                        ->where('id', 1)
                        ->increment('revision');

                    if ($lockAffected !== 1) {
                        return [
                            'success' => false,
                            'status' => 503,
                            'code' => 'intake_unavailable',
                            'message' => 'V3 access lock is unavailable.',
                        ];
                    }

                    // Recheck active teacher status
                    $user = DB::table('users')->where('id', $actorUserId)->first();
                    $access = DB::table('v3_account_access')->where('user_id', $actorUserId)->first();

                    if (! $user || $user->role !== 'teacher' || ! $access || ! (bool) $access->is_active) {
                        throw new HttpException(403, 'V3 access is unavailable.');
                    }

                    // 2. Resolve and lock owned Document
                    $document = Document::where('id', $documentId)
                        ->where('owner_user_id', $actorUserId)
                        ->lockForUpdate()
                        ->first();

                    if (! $document) {
                        return [
                            'success' => false,
                            'status' => 404,
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ];
                    }

                    if ($document->state !== 'ready') {
                        return [
                            'success' => false,
                            'status' => 409,
                            'code' => 'document_not_ready',
                            'message' => 'Document is not in ready state.',
                        ];
                    }

                    // Verify private file matches byte_size and sha256
                    $disk = Storage::disk('v3_private');
                    if (! $disk->exists($document->storage_path)) {
                        return [
                            'success' => false,
                            'status' => 503,
                            'code' => 'storage_unavailable',
                            'message' => 'Accepted document file is unavailable.',
                        ];
                    }

                    $stream = $disk->readStream($document->storage_path);
                    if (! is_resource($stream)) {
                        return [
                            'success' => false,
                            'status' => 503,
                            'code' => 'storage_unavailable',
                            'message' => 'Accepted document file stream is unavailable.',
                        ];
                    }

                    $ctx = hash_init('sha256');
                    hash_update_stream($ctx, $stream);
                    $actualSha = hash_final($ctx);
                    fclose($stream);

                    if ($actualSha !== $document->sha256) {
                        return [
                            'success' => false,
                            'status' => 503,
                            'code' => 'storage_unavailable',
                            'message' => 'Accepted document file checksum mismatch.',
                        ];
                    }

                    // 3. Inspect idempotency key
                    $existingKeyCase = ProcessingCase::where('owner_user_id', $actorUserId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existingKeyCase) {
                        if ($existingKeyCase->document_id === $document->id && $existingKeyCase->action === $action) {
                            // Identical replay: return existing case with 200 and original settings
                            return [
                                'success' => true,
                                'is_replay' => true,
                                'case' => $existingKeyCase,
                            ];
                        }

                        // Conflicting reuse of key
                        return [
                            'success' => false,
                            'status' => 409,
                            'code' => 'idempotency_conflict',
                            'message' => 'Idempotency key has already been used with different parameters.',
                        ];
                    }

                    // 4. Duplicate choice rules for new key
                    $priorCaseCount = ProcessingCase::where('owner_user_id', $actorUserId)
                        ->where('document_id', $document->id)
                        ->count();

                    if ($action === 'process' && $priorCaseCount > 0) {
                        return [
                            'success' => false,
                            'status' => 409,
                            'code' => 'duplicate_choice_required',
                            'message' => 'A processing case already exists for this document. Use process_again to start another run.',
                        ];
                    }

                    if ($action === 'process_again' && $priorCaseCount === 0) {
                        return [
                            'success' => false,
                            'status' => 409,
                            'code' => 'previous_case_required',
                            'message' => 'No previous processing case exists for this document. Use process to start the first run.',
                        ];
                    }

                    // 5. Select 005 settings pointer on same connection
                    try {
                        $settings = $this->settingsSelector->selectForNewCase();
                    } catch (SettingsUnavailable $e) {
                        return [
                            'success' => false,
                            'status' => 503,
                            'code' => 'settings_unavailable',
                            'message' => 'V3 settings are unavailable.',
                        ];
                    }

                    // 6. Verify current limits against Document metadata
                    $payload = is_array($settings->payload) ? $settings->payload : json_decode($settings->payload, true);
                    $limits = $payload['limits'] ?? [];
                    $maxBytes = (int) ($limits['max_pdf_bytes'] ?? 104857600);
                    $maxPages = (int) ($limits['max_pdf_pages'] ?? 1000);

                    if ($document->byte_size > $maxBytes || $document->page_count > $maxPages) {
                        return [
                            'success' => false,
                            'status' => 409,
                            'code' => 'document_exceeds_current_limits',
                            'message' => 'Document exceeds current active settings limits.',
                        ];
                    }

                    // 7. Determine protocol & capture framework
                    $protocol = config('services.v3.notebook_protocol', 'legacy_staged_v1');
                    $frameworkVersionId = null;
                    $notebookPolicy = null;

                    if ($protocol === 'notebooklm_combined_v1') {
                        $activeFramework = app(\App\Services\V3\NotebookLM\FrameworkCatalog::class)->getActiveFramework();
                        if (! $activeFramework) {
                            return [
                                'success' => false,
                                'status' => 503,
                                'code' => 'framework_unavailable',
                                'message' => 'Active notebook framework is unavailable.',
                            ];
                        }
                        $frameworkVersionId = $activeFramework->id;
                        $notebookPolicy = [
                            'adapter_kind' => 'notebooklm_py',
                            'adapter_version' => '0.8.4',
                            'credential_profile' => 'v3-local',
                            'response_format_version' => 'combined-envelope-v1',
                            'readiness_poll_seconds' => 10,
                            'readiness_attempt_seconds' => 300,
                            'query_timeout_seconds' => 600,
                            'max_answer_bytes' => 1048576,
                        ];
                    } else {
                        $protocol = 'legacy_staged_v1';
                    }

                    // 8. Case insert
                    $case = ProcessingCase::forceCreate([
                        'id' => (string) Str::uuid(),
                        'owner_user_id' => $actorUserId,
                        'document_id' => $document->id,
                        'settings_version_id' => $settings->id,
                        'idempotency_key' => $idempotencyKey,
                        'action' => $action,
                        'state' => 'awaiting_workflow',
                        'state_version' => 1,
                        'pipeline_version' => $protocol,
                        'notebook_framework_version_id' => $frameworkVersionId,
                        'notebook_policy' => $notebookPolicy,
                    ]);

                    return [
                        'success' => true,
                        'is_replay' => false,
                        'case' => $case,
                    ];
                }, 1);
            } catch (QueryException $e) {
                // If unique constraint violation on (owner_user_id, idempotency_key) from concurrent race:
                $existing = ProcessingCase::where('owner_user_id', $actorUserId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    if ($existing->document_id === $documentId && $existing->action === $action) {
                        return [
                            'success' => true,
                            'is_replay' => true,
                            'case' => $existing,
                        ];
                    }

                    return [
                        'success' => false,
                        'status' => 409,
                        'code' => 'idempotency_conflict',
                        'message' => 'Idempotency key has already been used with different parameters.',
                    ];
                }

                if ($attempt < $maxAttempts && $this->isRetryableQueryException($e)) {
                    usleep(15000 * $attempt);
                    continue;
                }

                return [
                    'success' => false,
                    'status' => 503,
                    'code' => 'intake_unavailable',
                    'message' => 'V3 intake is temporarily unavailable due to concurrent contention.',
                ];
            }
        }

        return [
            'success' => false,
            'status' => 503,
            'code' => 'intake_unavailable',
            'message' => 'V3 intake transaction retry budget exhausted.',
        ];
    }

    protected function isRetryableQueryException(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'locked')
            || str_contains($message, 'busy')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'serialization')
            || in_array($e->getCode(), [1213, 1205, '40001'], true);
    }
}
