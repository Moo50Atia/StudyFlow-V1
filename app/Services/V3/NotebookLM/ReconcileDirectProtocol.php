<?php

namespace App\Services\V3\NotebookLM;

use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonQuery;
use App\Models\V3\NotebookLessonBundle;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Admin\CaseOperationGuard;
use App\Services\V3\Lessons\LessonEvidenceStore;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReconcileDirectProtocol
{
    public function __construct(
        protected BridgeClient $bridgeClient,
        protected CaseOperationGuard $operationGuard,
        protected AcceptCombinedLesson $acceptCombinedLesson,
        protected LessonEvidenceStore $evidenceStore
    ) {}

    /**
     * Reconcile uncertain direct protocol queries, bundles, and signals.
     */
    public function reconcile(): array
    {
        $reconciledCompletions = 0;
        $reconciledBundles = 0;
        $fencedCases = 0;

        $cases = ProcessingCase::where('pipeline_version', 'notebooklm_combined_v1')->get();

        foreach ($cases as $case) {
            // Respect hold and cancel fences: retain evidence, do not advance cursor
            if (! $this->operationGuard->isPermitted($case->id)) {
                $fencedCases++;
                continue;
            }

            // 1. Reconcile uncertain completion signals
            $uncertainCompletions = LessonCompletion::where('case_id', $case->id)
                ->where('signal_state', 'uncertain')
                ->get();

            foreach ($uncertainCompletions as $comp) {
                try {
                    $workflowId = "v3-case-{$comp->case_id}";
                    $resp = $this->bridgeClient->notifyLessonReady(
                        $workflowId,
                        [
                            'schema_version' => 1,
                            'case_id' => $comp->case_id,
                            'map_ref' => $comp->map_id,
                            'work_ordinal' => $comp->work_ordinal,
                            'cursor_version' => $comp->cursor_version,
                            'completion_ref' => $comp->completion_ref,
                        ]
                    );

                    if (($resp['status'] ?? 0) === 202) {
                        $comp->update([
                            'signal_state' => 'accepted',
                            'signal_accepted_at' => now(),
                        ]);
                        $reconciledCompletions++;
                    }
                } catch (\Throwable) {
                    // Safe handling, no secret logging
                }
            }

            // 2. Reconcile uncertain queries
            $uncertainQueries = LessonQuery::where('case_id', $case->id)
                ->where('state', 'uncertain')
                ->whereIn('kind', ['lesson_start', 'lesson_continue'])
                ->get();

            foreach ($uncertainQueries as $query) {
                // If bundle is already created for this query, mark query recorded
                if (NotebookLessonBundle::where('case_id', $case->id)->where('query_id', $query->id)->exists()) {
                    $query->update(['state' => 'recorded']);
                    continue;
                }

                // If query has answer_path stored on disk, attempt to commit without resending
                if ($query->answer_path && Storage::disk('v3_private')->exists($query->answer_path)) {
                    $answer = Storage::disk('v3_private')->get($query->answer_path);
                    if (! empty($answer) && $query->conversation_out
                        && $query->conversation_out === $query->conversation_in) {
                        try {
                            $this->acceptCombinedLesson->acceptCombinedLesson(
                                queryRef: $query->id,
                                payload: [
                                    'schema_version' => 1,
                                    'expected_cursor_version' => $query->expected_cursor_version,
                                    'answer' => $answer,
                                    'answer_sha256' => $query->answer_sha256 ?? hash('sha256', $answer),
                                    'conversation_out' => $query->conversation_out,
                                ]
                            );
                            $reconciledBundles++;
                        } catch (\Throwable) {
                            Log::warning("Direct protocol bundle commit failed during reconciliation", [
                                'case_id' => $case->id,
                                'query_id' => $query->id,
                                'error_code' => 'commit_unavailable',
                            ]);
                        }
                    }
                }
                // Zero matching history or no answer remains uncertain: NEVER reset send_count
            }
        }

        return [
            'reconciled_completions' => $reconciledCompletions,
            'reconciled_bundles' => $reconciledBundles,
            'fenced_cases' => $fencedCases,
        ];
    }
}
