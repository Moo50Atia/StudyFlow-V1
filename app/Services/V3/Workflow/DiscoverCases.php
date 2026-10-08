<?php

namespace App\Services\V3\Workflow;

use App\Jobs\V3\StartCaseWorkflowJob;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\DB;

class DiscoverCases
{
    public function discover(): int
    {
        $dispatched = 0;

        // 1. Discover awaiting_workflow cases without a link
        $casesWithoutLink = ProcessingCase::where('state', 'awaiting_workflow')
            ->whereDoesntHave('workflowLink')
            ->get();

        foreach ($casesWithoutLink as $case) {
            $created = DB::transaction(function () use ($case) {
                $lockedCase = ProcessingCase::where('id', $case->id)->lockForUpdate()->first();
                if (! $lockedCase || $lockedCase->state !== 'awaiting_workflow') {
                    return false;
                }

                $existingLink = WorkflowLink::where('case_id', $case->id)->lockForUpdate()->first();
                if ($existingLink) {
                    return false;
                }

                WorkflowLink::forceCreate([
                    'case_id' => $case->id,
                    'workflow_id' => "v3-case-{$case->id}",
                    'settings_version_id' => $case->settings_version_id,
                    'settings_checksum' => null,
                    'start_state' => 'pending',
                    'lease_until' => now()->addMinutes(2),
                ]);

                return true;
            });

            if ($created) {
                StartCaseWorkflowJob::dispatch($case->id)->afterCommit();
                $dispatched++;
            }
        }

        // 2. Discover pending/uncertain links with expired lease
        $expiredLinks = WorkflowLink::whereIn('start_state', ['pending', 'uncertain'])
            ->where(function ($query) {
                $query->whereNull('lease_until')
                    ->orWhere('lease_until', '<', now());
            })
            ->whereHas('processingCase', function ($query) {
                $query->where('state', 'awaiting_workflow');
            })
            ->get();

        foreach ($expiredLinks as $link) {
            $claimed = DB::transaction(function () use ($link) {
                $lockedLink = WorkflowLink::where('case_id', $link->case_id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedLink) {
                    return false;
                }

                if (! in_array($lockedLink->start_state, ['pending', 'uncertain'], true)) {
                    return false;
                }

                if ($lockedLink->lease_until !== null && $lockedLink->lease_until > now()) {
                    return false;
                }

                $lockedLink->lease_until = now()->addMinutes(2);
                $lockedLink->save();

                return true;
            });

            if ($claimed) {
                StartCaseWorkflowJob::dispatch($link->case_id)->afterCommit();
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
