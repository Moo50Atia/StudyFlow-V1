<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\SubjectRun;
use App\Models\V3\TeacherSection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RunProgress
{
    public function __construct(protected TerminalBridgeClient $bridgeClient) {}

    /**
     * Build the progress projection for a subject run.
     *
     * @return array<string, mixed>
     */
    public function project(SubjectRun $run): array
    {
        $run->loadMissing('items');

        // Bounded counts derivation
        $pdfsTotal = (int) ($run->total_pdfs ?: $run->items->count());
        $pdfsAccepted = $run->items->filter(fn ($i) => ! empty($i->document_id) && $i->state !== 'failed')->count();
        $pdfsFailed = $run->items->filter(fn ($i) => $i->state === 'failed' || $i->outcome === 'failed')->count();

        // Case IDs for this run
        $caseIds = $run->items->pluck('case_id')->filter()->values()->all();

        // Saved lessons & prompts
        $lessonsSaved = 0;
        $promptsSaved = 0;
        foreach ($run->items as $item) {
            $lessonsSaved += (int) ($item->saved_lesson_count ?: 0);
            $promptsSaved += (int) ($item->prompt_count ?: 0);
        }

        if ($lessonsSaved === 0 && ! empty($caseIds)) {
            $lessonsSaved = (int) DB::table('v3_teacher_sections')->whereIn('case_id', $caseIds)->count();
        }
        if ($promptsSaved === 0 && ! empty($caseIds)) {
            $promptsSaved = (int) DB::table('v3_scene_prompt_versions')->whereIn('case_id', $caseIds)->count();
        }

        // Scenes and candidates
        $scenesGenerated = 0;
        $scenesBuilt = 0;
        $scenesChecked = 0;
        $scenesRealQualified = 0;
        $scenesDemoReviewReady = 0;
        $reviewsPending = 0;
        $scenesApproved = 0;

        if (! empty($caseIds)) {
            $scenesGenerated = (int) DB::table('v3_scene_candidates')
                ->whereIn('case_id', $caseIds)
                ->where('origin', 'provider_generated')
                ->count();

            $scenesBuilt = (int) DB::table('v3_scene_candidates')
                ->whereIn('case_id', $caseIds)
                ->count();

            $scenesChecked = (int) DB::table('v3_scene_check_runs')
                ->whereIn('case_id', $caseIds)
                ->where('verdict', 'pass')
                ->count();

            $scenesRealQualified = (int) DB::table('v3_qualification_gates')
                ->whereIn('case_id', $caseIds)
                ->where('gate_stage', 'scene')
                ->where('basis', 'real_jev')
                ->count();

            $scenesDemoReviewReady = (int) DB::table('v3_scene_candidate_states')
                ->whereIn('case_id', $caseIds)
                ->where('status', 'demo_review_ready')
                ->count();

            $reviewsPending = (int) DB::table('v3_teacher_review_items')
                ->whereIn('case_id', $caseIds)
                ->whereIn('status', ['pending', 'dormant'])
                ->count();

            $scenesApproved = (int) DB::table('v3_approved_scenes')
                ->whereIn('case_id', $caseIds)
                ->count();
        }

        // If counters_json is stored on run, overlay it
        if (! empty($run->counters_json)) {
            $storedCounters = json_decode($run->counters_json, true);
            if (is_array($storedCounters)) {
                $scenesBuilt = $storedCounters['scenes_built'] ?? $scenesBuilt;
                $scenesChecked = $storedCounters['scenes_checked'] ?? $scenesChecked;
            }
        }

        // Latest control command
        $control = null;
        $latestCommand = DB::table('v3_pipeline_commands')
            ->where('run_id', $run->id)
            ->whereIn('action', ['pause', 'resume', 'cancel', 'retry', 'generate'])
            ->latest('created_at')
            ->first();

        if ($latestCommand) {
            $control = [
                'command_id' => $latestCommand->id,
                'state' => $latestCommand->state,
                'action' => $latestCommand->action,
            ];
        }

        $lastUpdated = $run->last_updated_at ? Carbon::parse($run->last_updated_at) : ($run->updated_at ?: now());
        $ageSeconds = abs((int) now()->diffInSeconds($lastUpdated));
        $projectionSource = $ageSeconds > 120 ? 'last_known' : 'live';
        $workflow = null;
        $runState = $run->state;
        try {
            $statusResponse = $this->bridgeClient->getRunStatus($run->id);
            $status = $statusResponse['status'] ?? null;
            if (is_string($status) && in_array($status, [
                'staging', 'queued', 'running', 'paused', 'paused_auth', 'paused_quota',
                'held_uncertain', 'held_persistence', 'completed', 'partial', 'failed', 'cancelled',
            ], true)) {
                $runState = $status;
                $projectionSource = 'live';
            }
            $candidate = $statusResponse['progress'] ?? null;
            if (is_array($candidate)) {
                $workflow = [
                    'phase' => $candidate['phase'] ?? null,
                    'generation_phase' => $candidate['generation_phase'] ?? null,
                    'current_pdf_ordinal' => $candidate['current_pdf_ordinal'] ?? null,
                    'paused' => (bool) ($candidate['paused'] ?? false),
                    'cancelled' => (bool) ($candidate['cancelled'] ?? false),
                ];
            }
        } catch (\Throwable) {
            // Keep the last persisted state and label its age below.
        }

        // Paged items (without teacher title strings for privacy boundary where needed)
        $itemsProjection = $run->items->sortBy('pdf_ordinal')->values()->map(function ($item) {
            return [
                'id' => $item->id,
                'ordinal' => $item->pdf_ordinal,
                'state' => $item->state,
                'outcome' => $item->outcome,
                'safe_code' => $item->safe_code ?? 'ok',
                'counts' => [
                    'saved_lessons' => $item->saved_lesson_count,
                    'prompts' => $item->prompt_count,
                ],
            ];
        })->all();

        return [
            'schema_version' => 1,
            'run_id' => $run->id,
            'state' => $runState,
            'generation_phase' => $run->generation_phase,
            'state_version' => (int) $run->state_version,
            'last_updated_at' => $lastUpdated->toIso8601ZuluString(),
            'projection_source' => $projectionSource,
            'projection_age_seconds' => $ageSeconds,
            'control' => $control,
            'workflow' => $workflow,
            'counts' => [
                'pdfs_total' => $pdfsTotal,
                'pdfs_accepted' => $pdfsAccepted,
                'pdfs_failed' => $pdfsFailed,
                'lessons_total' => $run->total_lessons !== null ? (int) $run->total_lessons : null,
                'lessons_saved' => $lessonsSaved,
                'prompts_saved' => $promptsSaved,
                'scenes_total' => $run->total_scenes !== null ? (int) $run->total_scenes : null,
                'scenes_generated' => $scenesGenerated,
                'scenes_built' => $scenesBuilt,
                'scenes_checked' => $scenesChecked,
                'scenes_real_qualified' => $scenesRealQualified,
                'scenes_demo_review_ready' => $scenesDemoReviewReady,
                'reviews_pending' => $reviewsPending,
                'scenes_approved' => $scenesApproved,
            ],
            'items' => $itemsProjection,
        ];
    }
}
