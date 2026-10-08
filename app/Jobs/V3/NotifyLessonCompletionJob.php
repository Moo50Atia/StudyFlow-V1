<?php

namespace App\Jobs\V3;

use App\Models\V3\LessonCompletion;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class NotifyLessonCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public string $caseId,
        public string $completionId
    ) {
        $this->onQueue('v3_workflow');
        $this->afterCommit = true;
    }

    public function handle(BridgeClient $bridgeClient): void
    {
        $completion = LessonCompletion::find($this->completionId);
        if (! $completion || $completion->signal_state === 'accepted') {
            return;
        }
        if (! is_int($completion->cursor_version) || $completion->cursor_version < 1) {
            throw new \RuntimeException('Lesson completion cursor version is unavailable.');
        }

        $link = WorkflowLink::where('case_id', $this->caseId)->first();
        if (! $link) {
            return;
        }

        $payload = [
            'schema_version' => 1,
            'case_id' => $completion->case_id,
            'map_ref' => $completion->map_id,
            'work_ordinal' => $completion->work_ordinal,
            'cursor_version' => $completion->cursor_version,
            'completion_ref' => $completion->completion_ref,
        ];

        $resp = $bridgeClient->notifyLessonReady($link->workflow_id, $payload);

        DB::transaction(function () use ($completion, $resp) {
            $locked = LessonCompletion::where('id', $completion->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $locked->signal_attempts = $locked->signal_attempts + 1;
            $locked->signal_attempted_at = now();

            if ($resp['status'] === 202 && ! empty($resp['data']['accepted'])) {
                $locked->signal_state = 'accepted';
                $locked->signal_accepted_at = now();
                $locked->lease_until = null;
            } elseif ($resp['status'] === 409) {
                $locked->signal_state = 'conflict';
                $locked->lease_until = null;
            } else {
                $locked->signal_state = 'uncertain';
                $locked->lease_until = null;
            }

            $locked->save();
        });
    }
}
