<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Prompts\PromptUnavailableException;
use Illuminate\Support\Facades\DB;

class ReconcileAuthoring
{
    public function __construct(
        protected QualifiedPromptResolver $promptResolver
    ) {}

    /**
     * Run reconciliation pass under lock.
     *
     * @return array{uncertain_attempts: int, stale_requests: int}
     */
    public function reconcile(): array
    {
        $uncertainAttempts = 0;
        $staleRequests = 0;

        // 1. Mark abandoned send_reserved attempts older than 5 minutes as uncertain
        $cutoff = now()->subMinutes(5);
        $abandonedAttempts = CodingAttempt::where('state', 'send_reserved')
            ->where(function ($query) use ($cutoff) {
                $query->where('send_reserved_at', '<=', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('send_reserved_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->get();

        foreach ($abandonedAttempts as $attempt) {
            DB::transaction(function () use ($attempt, &$uncertainAttempts) {
                $lockedAttempt = CodingAttempt::where('id', $attempt->id)->lockForUpdate()->first();
                if ($lockedAttempt && $lockedAttempt->state === 'send_reserved') {
                    $lockedAttempt->update([
                        'state' => 'uncertain',
                        'failure_code' => 'timeout',
                    ]);

                    $request = AuthoringRequest::where('id', $lockedAttempt->request_id)->lockForUpdate()->first();
                    if ($request && in_array($request->state, ['pending', 'authoring'], true)) {
                        $request->update([
                            'state' => 'uncertain',
                            'state_version' => $request->state_version + 1,
                        ]);
                    }

                    $uncertainAttempts++;
                }
            });
        }

        // 2. Mark requests stale if prompt was superseded and no live send is active
        $activeRequests = AuthoringRequest::whereIn('state', ['pending', 'authoring'])->get();
        foreach ($activeRequests as $request) {
            DB::transaction(function () use ($request, &$staleRequests) {
                $lockedRequest = AuthoringRequest::where('id', $request->id)->lockForUpdate()->first();
                if (! $lockedRequest || ! in_array($lockedRequest->state, ['pending', 'authoring'], true)) {
                    return;
                }

                // Skip if there is an active send_reserved attempt
                $hasActiveSend = CodingAttempt::where('request_id', $lockedRequest->id)
                    ->where('state', 'send_reserved')
                    ->exists();

                if ($hasActiveSend) {
                    return;
                }

                try {
                    $this->promptResolver->resolve($lockedRequest->case_id, $lockedRequest->prompt_version_id);
                } catch (PromptUnavailableException $e) {
                    $lockedRequest->update([
                        'state' => 'stale',
                        'state_version' => $lockedRequest->state_version + 1,
                    ]);
                    $staleRequests++;
                }
            });
        }

        return [
            'uncertain_attempts' => $uncertainAttempts,
            'stale_requests' => $staleRequests,
        ];
    }
}
