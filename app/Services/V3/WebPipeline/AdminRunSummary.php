<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\SubjectRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminRunSummary
{
    public function __construct(
        protected RunProgress $progress
    ) {}

    /**
     * Safe summary list for Admin index.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listRuns(int $limit = 25): Collection
    {
        $limit = max(1, min($limit, 100));

        $runs = SubjectRun::orderByDesc('created_at')->limit($limit)->get();

        return $runs->map(fn (SubjectRun $run) => $this->summarizeRow($run));
    }

    /**
     * Safe single run summary for Admin show.
     *
     * @return array<string, mixed>
     */
    public function getSummary(SubjectRun $run): array
    {
        $projection = $this->progress->project($run);

        // Sanitize items: strip titles, filenames, hashes, paths
        $safeItems = $run->items->sortBy('pdf_ordinal')->values()->map(function ($item) {
            return [
                'id' => $item->id,
                'pdf_ordinal' => $item->pdf_ordinal,
                'state' => $item->state,
                'outcome' => $item->outcome,
                'safe_code' => $item->safe_code ?? 'ok',
                'state_version' => (int) $item->state_version,
                'counts' => [
                    'saved_lessons' => $item->saved_lesson_count,
                    'prompts' => $item->prompt_count,
                ],
            ];
        })->all();

        return [
            'schema_version' => 1,
            'run_id' => $run->id,
            'execution_mode' => $run->execution_mode,
            'recovery_task_id' => $this->recoveryTaskId($run),
            'subject_id' => $run->subject_id,
            'owner_user_id' => $run->owner_user_id,
            'origin' => $run->origin,
            'protocol' => $run->protocol,
            'mode' => $run->mode,
            'qualification_mode' => $run->qualification_mode,
            'state' => $projection['state'] ?? $run->state,
            'state_version' => (int) $run->state_version,
            'last_updated_at' => $projection['last_updated_at'],
            'projection_source' => $projection['projection_source'],
            'projection_age_seconds' => $projection['projection_age_seconds'],
            'control' => $projection['control'],
            'failures' => $projection['failures'] ?? [],
            'retry_available' => $projection['retry_available'] ?? false,
            'counts' => $projection['counts'],
            'workflow' => $projection['workflow'],
            'items' => $safeItems,
            'created_at' => $run->created_at?->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Safe summary row for a run in list view.
     *
     * @return array<string, mixed>
     */
    public function summarizeRow(SubjectRun $run): array
    {
        $projection = $this->progress->project($run);
        return [
            'run_id' => $run->id,
            'execution_mode' => $run->execution_mode,
            'recovery_task_id' => $this->recoveryTaskId($run),
            'subject_id' => $run->subject_id,
            'owner_user_id' => $run->owner_user_id,
            'origin' => $run->origin,
            'protocol' => $run->protocol,
            'mode' => $run->mode,
            'qualification_mode' => $run->qualification_mode,
            'state' => $projection['state'] ?? $run->state,
            'failures' => $projection['failures'] ?? [],
            'retry_available' => $projection['retry_available'] ?? false,
            'state_version' => (int) $run->state_version,
            'total_pdfs' => (int) $run->total_pdfs,
            'created_at' => $run->created_at?->toIso8601ZuluString(),
            'last_updated_at' => $run->last_updated_at?->toIso8601ZuluString() ?? $run->updated_at?->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->toIso8601ZuluString(),
        ];
    }

    private function recoveryTaskId(SubjectRun $run): ?string
    {
        if ($run->execution_mode !== 'local_worker_v1') return null;
        return DB::table('v3_local_tasks as t')
            ->join('v3_local_jobs as j', 'j.id', '=', 't.job_id')
            ->where('j.run_id', $run->id)
            ->whereIn('t.status', ['reconciliation_required', 'paused_auth', 'paused_quota'])
            ->where('t.task_kind', 'author_prompts')->value('t.id');
    }
}
