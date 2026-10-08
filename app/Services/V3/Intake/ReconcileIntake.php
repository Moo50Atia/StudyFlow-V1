<?php

namespace App\Services\V3\Intake;

use App\Jobs\V3\ValidatePdfUpload;
use App\Models\V3\Document;
use App\Models\V3\UploadAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileIntake
{
    public function __construct(
        protected PrivatePdfStore $store
    ) {}

    public function reconcile(): array
    {
        $stats = [
            'cleanup_reconciled' => 0,
            'promoting_reconciled' => 0,
            'expired_blocked' => 0,
            'due_dispatched' => 0,
            'orphans_purged' => 0,
        ];

        if (! \Illuminate\Support\Facades\Schema::hasTable('v3_upload_attempts') || ! \Illuminate\Support\Facades\Schema::hasTable('v3_documents')) {
            return $stats;
        }

        // 1. Reconcile cleanup_pending attempts
        $cleanupAttempts = UploadAttempt::where('state', 'cleanup_pending')->get();
        foreach ($cleanupAttempts as $attempt) {
            if ($attempt->staging_path) {
                $deleted = $this->store->delete($attempt->staging_path);
                if ($deleted) {
                    $attempt->staging_path = null;
                    $attempt->state = $attempt->cleanup_target ?? 'rejected';
                    $attempt->finished_at = now();
                    $attempt->save();
                    $stats['cleanup_reconciled']++;
                } else {
                    if ($attempt->expires_at && $attempt->expires_at->isPast()) {
                        Log::error('cleanup_overdue', ['attempt_id' => $attempt->id]);
                    }
                }
            } else {
                $attempt->state = $attempt->cleanup_target ?? 'rejected';
                $attempt->finished_at = now();
                $attempt->save();
                $stats['cleanup_reconciled']++;
            }
        }

        // 2. Reconcile promoting attempts / pending_move documents
        $promotingAttempts = UploadAttempt::where('state', 'promoting')->get();
        foreach ($promotingAttempts as $attempt) {
            $doc = Document::find($attempt->document_id);
            if (! $doc || $doc->state !== 'pending_move') {
                continue;
            }

            $accRelative = $doc->storage_path;
            $dstExists = $this->store->exists($accRelative);

            if ($dstExists &&
                $this->store->size($accRelative) === $doc->byte_size &&
                $this->store->sha256($accRelative) === $doc->sha256) {
                // Destination matches completely -> finalize
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

                if ($attempt->staging_path) {
                    $this->store->delete($attempt->staging_path);
                }
                $stats['promoting_reconciled']++;
            } elseif ($attempt->staging_path && $this->store->exists($attempt->staging_path)) {
                // Source exists, retry rename
                try {
                    $this->store->promoteQuarantineToAccepted($attempt->id, $doc->id);
                    if ($this->store->exists($accRelative) &&
                        $this->store->size($accRelative) === $doc->byte_size &&
                        $this->store->sha256($accRelative) === $doc->sha256) {
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
                        $stats['promoting_reconciled']++;
                    }
                } catch (\Throwable) {
                    // stays for next retry
                }
            } else {
                // Neither destination nor source verified -> delete unreferenced pending document & block attempt
                DB::transaction(function () use ($doc, $attempt, $accRelative) {
                    $this->store->delete($accRelative);
                    $doc->delete();

                    $attempt->state = 'cleanup_pending';
                    $attempt->cleanup_target = 'blocked';
                    $attempt->reason_code = 'internal';
                    $attempt->lease_until = null;
                    $attempt->save();
                });
                $stats['promoting_reconciled']++;
            }
        }

        // 3. Mark expired attempts as cleanup_pending / blocked
        $expiredAttempts = UploadAttempt::whereIn('state', ['receiving', 'quarantined', 'retry_pending'])
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredAttempts as $attempt) {
            $attempt->state = 'cleanup_pending';
            $attempt->cleanup_target = 'blocked';
            $attempt->reason_code = 'quarantine_expired';
            $attempt->lease_until = null;
            $attempt->save();

            $deleted = $this->store->delete($attempt->staging_path ?? '');
            if ($deleted) {
                $attempt->staging_path = null;
                $attempt->state = 'blocked';
                $attempt->finished_at = now();
                $attempt->save();
            }
            $stats['expired_blocked']++;
        }

        // 4. Dispatch or process due quarantined/retry_pending attempts
        $dueAttempts = UploadAttempt::whereIn('state', ['quarantined', 'retry_pending'])
            ->where(function ($q) {
                $q->whereNull('lease_until')->orWhere('lease_until', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->where('expires_at', '>', now())
            ->get();

        foreach ($dueAttempts as $attempt) {
            ValidatePdfUpload::dispatch($attempt->id)->onQueue('v3_intake');
            $stats['due_dispatched']++;
        }

        return $stats;
    }
}
