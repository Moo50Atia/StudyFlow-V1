<?php

namespace App\Services\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IntakeCoordinator
{
    public function __construct(
        protected PrivatePdfStore $store,
        protected PdfInspector $inspector
    ) {}

    public function processAttempt(string $attemptId): void
    {
        // 1. Claim attempt atomically
        $attempt = DB::transaction(function () use ($attemptId) {
            $att = UploadAttempt::where('id', $attemptId)
                ->whereIn('state', ['quarantined', 'retry_pending'])
                ->where(function ($q) {
                    $q->whereNull('lease_until')->orWhere('lease_until', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
                })
                ->lockForUpdate()
                ->first();

            if (! $att) {
                return null;
            }

            $att->state = 'validating';
            $att->lease_until = now()->addMinutes(5);
            $att->scan_attempts += 1;
            $att->save();

            return $att;
        });

        if (! $attempt) {
            return;
        }

        // 2. Check 24h absolute quarantine deadline
        if ($attempt->expires_at && $attempt->expires_at->isPast()) {
            $attempt->state = 'cleanup_pending';
            $attempt->cleanup_target = 'blocked';
            $attempt->reason_code = 'quarantine_expired';
            $attempt->lease_until = null;
            $attempt->save();

            $this->cleanupAttempt($attempt);
            return;
        }

        // 3. Inspect file
        if (! $attempt->staging_path || ! $this->store->exists($attempt->staging_path)) {
            $attempt->state = 'cleanup_pending';
            $attempt->cleanup_target = 'blocked';
            $attempt->reason_code = 'storage_unavailable';
            $attempt->lease_until = null;
            $attempt->save();

            $this->cleanupAttempt($attempt);
            return;
        }

        $maxPages = 1000;
        if ($attempt->upload_settings_version_id) {
            $settings = SettingsVersion::find($attempt->upload_settings_version_id);
            if ($settings && is_array($settings->payload)) {
                $maxPages = (int) ($settings->payload['limits']['max_pdf_pages'] ?? 1000);
            }
        }

        $filePath = $this->store->fullPath($attempt->staging_path);
        $result = $this->inspector->inspect($filePath, $maxPages);

        if (! $result['valid']) {
            $reason = $result['reason_code'] ?? 'internal';
            $errorType = $result['error_type'] ?? 'deterministic';

            if ($errorType === 'deterministic') {
                $attempt->state = 'cleanup_pending';
                $attempt->cleanup_target = 'rejected';
                $attempt->reason_code = $reason;
                $attempt->lease_until = null;
                $attempt->save();

                $this->cleanupAttempt($attempt);
                return;
            }

            // Transient technical failure
            $attempt->technical_failures += 1;
            if ($attempt->technical_failures > 3) {
                $attempt->state = 'cleanup_pending';
                $attempt->cleanup_target = 'blocked';
                $attempt->reason_code = $reason;
                $attempt->lease_until = null;
                $attempt->save();

                $this->cleanupAttempt($attempt);
                return;
            }

            // Schedule retry 60s, 120s, 240s
            $delays = [1 => 60, 2 => 120, 3 => 240];
            $delay = $delays[$attempt->technical_failures] ?? 240;

            $attempt->state = 'retry_pending';
            $attempt->reason_code = $reason;
            $attempt->next_retry_at = now()->addSeconds($delay);
            $attempt->lease_until = null;
            $attempt->save();
            return;
        }

        // 4. Valid PDF: Deduplicate or Promote
        $this->promoteOrDeduplicate($attempt, $result['page_count'] ?? 1);
    }

    public function promoteOrDeduplicate(UploadAttempt $attempt, int $pageCount): void
    {
        $sha256 = $this->store->sha256($attempt->staging_path);
        $byteSize = $this->store->size($attempt->staging_path);

        $shouldPromote = false;
        $documentId = null;

        try {
            DB::transaction(function () use ($attempt, $sha256, $byteSize, $pageCount, &$shouldPromote, &$documentId) {
                $existingDoc = Document::where('owner_user_id', $attempt->owner_user_id)
                    ->where('sha256', $sha256)
                    ->lockForUpdate()
                    ->first();

                if ($existingDoc) {
                    if ($existingDoc->state === 'ready') {
                        // Verify stored file
                        $accRelative = $existingDoc->storage_path;
                        if (! $this->store->exists($accRelative) ||
                            $this->store->size($accRelative) !== $existingDoc->byte_size ||
                            $this->store->sha256($accRelative) !== $existingDoc->sha256) {
                            $attempt->state = 'cleanup_pending';
                            $attempt->cleanup_target = 'blocked';
                            $attempt->reason_code = 'storage_unavailable';
                            $attempt->lease_until = null;
                            $attempt->save();

                            $this->cleanupAttempt($attempt);
                            return;
                        }

                        // Clean quarantine bytes
                        $this->store->deleteQuarantine($attempt->id);

                        $attempt->staging_path = null;
                        $attempt->document_id = $existingDoc->id;
                        $attempt->duplicate = true;
                        $attempt->state = 'accepted';
                        $attempt->reason_code = null;
                        $attempt->lease_until = null;
                        $attempt->finished_at = now();
                        $attempt->save();
                        return;
                    }

                    if ($existingDoc->state === 'pending_move') {
                        // Wait for promotion without spending technical retries
                        $attempt->state = 'retry_pending';
                        $attempt->lease_until = null;
                        $attempt->next_retry_at = now()->addSeconds(30);
                        $attempt->save();
                        return;
                    }
                }

                // New Document: insert pending_move and mark promoting
                $documentId = (string) Str::uuid();
                Document::forceCreate([
                    'id' => $documentId,
                    'owner_user_id' => $attempt->owner_user_id,
                    'sha256' => $sha256,
                    'byte_size' => $byteSize,
                    'page_count' => $pageCount,
                    'state' => 'pending_move',
                    'storage_path' => $this->store->acceptedPath($documentId),
                ]);

                $attempt->state = 'promoting';
                $attempt->document_id = $documentId;
                $attempt->save();

                $shouldPromote = true;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Concurrent race: Another process inserted Document with same (owner_user_id, sha256)
            $existingDoc = Document::where('owner_user_id', $attempt->owner_user_id)
                ->where('sha256', $sha256)
                ->first();

            if ($existingDoc && $existingDoc->state === 'ready') {
                $this->store->deleteQuarantine($attempt->id);
                $attempt->staging_path = null;
                $attempt->document_id = $existingDoc->id;
                $attempt->duplicate = true;
                $attempt->state = 'accepted';
                $attempt->reason_code = null;
                $attempt->lease_until = null;
                $attempt->finished_at = now();
                $attempt->save();
                return;
            }

            $attempt->state = 'retry_pending';
            $attempt->lease_until = null;
            $attempt->next_retry_at = now()->addSeconds(30);
            $attempt->save();
            return;
        }

        if ($shouldPromote && $documentId) {
            $doc = Document::find($documentId);
            try {
                $this->store->promoteQuarantineToAccepted($attempt->id, $documentId);

                $accPath = $doc->storage_path;
                if ($this->store->exists($accPath) &&
                    $this->store->size($accPath) === $doc->byte_size &&
                    $this->store->sha256($accPath) === $doc->sha256) {
                    DB::transaction(function () use ($doc, $attempt) {
                        $doc->state = 'ready';
                        $doc->accepted_at = now();
                        $doc->save();

                        $attempt->state = 'accepted';
                        $attempt->duplicate = false;
                        $attempt->staging_path = null;
                        $attempt->lease_until = null;
                        $attempt->finished_at = now();
                        $attempt->reason_code = null;
                        $attempt->save();
                    });
                } else {
                    throw new \RuntimeException('Promoted file verification failed.');
                }
            } catch (\Throwable) {
                // Promotion crash recovery handled by ReconcileIntake
            }
        }
    }

    public function cleanupAttempt(UploadAttempt $attempt): void
    {
        if ($attempt->staging_path) {
            $deleted = $this->store->delete($attempt->staging_path);
            if ($deleted) {
                $attempt->staging_path = null;
                $attempt->state = $attempt->cleanup_target ?? 'rejected';
                $attempt->finished_at = now();
                $attempt->lease_until = null;
                $attempt->save();
            } else {
                if ($attempt->expires_at && $attempt->expires_at->isPast()) {
                    Log::error('cleanup_overdue', ['attempt_id' => $attempt->id]);
                }
            }
        } else {
            $attempt->state = $attempt->cleanup_target ?? 'rejected';
            $attempt->finished_at = now();
            $attempt->lease_until = null;
            $attempt->save();
        }
    }
}
