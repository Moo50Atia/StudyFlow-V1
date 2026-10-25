<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
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
        $caseIds = $run->items->sortBy('pdf_ordinal')->pluck('case_id')->filter()->values()->all();

        // Saved lessons & prompts
        $lessonsSaved = 0;
        $promptsSaved = 0;
        $caseLessons = empty($caseIds) ? collect() : DB::table('v3_teacher_sections')
            ->whereIn('case_id', $caseIds)->groupBy('case_id')
            ->select('case_id')->selectRaw('COUNT(DISTINCT work_node_id) as saved')->pluck('saved', 'case_id');
        $casePrompts = empty($caseIds) ? collect() : DB::table('v3_scene_prompt_versions')
            ->whereIn('case_id', $caseIds)->groupBy('case_id')
            ->select('case_id')->selectRaw('COUNT(*) as saved')->pluck('saved', 'case_id');
        foreach ($run->items as $item) {
            $lessonsSaved += max((int) $item->saved_lesson_count, (int) ($caseLessons[$item->case_id] ?? 0));
            $promptsSaved += max((int) $item->prompt_count, (int) ($casePrompts[$item->case_id] ?? 0));
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
        $failures = [];
        $statusResponse = [];
        $runState = $run->state;
        $knownLessonTotal = null;
        $completedLessons = [];
        $localJob = LocalJob::where('run_id', $run->id)->first();
        $isLocalWorker = $run->execution_mode === 'local_worker_v1' || $localJob !== null;
        $outputScope = $localJob ? $localJob->output_scope : ($run->captured_settings['output_scope'] ?? 'prompts_only');

        if ($isLocalWorker) {
            $projectionSource = 'local_worker';

            if ($localJob) {
                $tasks = $localJob->tasks()->get();
                $sceneReturnTask = $tasks->firstWhere('task_kind', 'return_scenes');
                $effects = LocalProviderEffect::where('job_id', $localJob->id)->get();

                if ($tasks->contains(fn($t) => $t->status === 'reconciliation_required') || $effects->contains(fn($e) => $e->status === 'unknown')) {
                    $runState = 'held_reconciliation';
                    $failures[] = ['code' => 'reconciliation_required', 'visibility' => 'teacher', 'message' => 'Ambiguous provider effect requires reconciliation.'];
                } elseif ($tasks->contains(fn($t) => $t->status === 'paused_auth') || $effects->contains(fn($e) => $e->status === 'authentication_required')) {
                    $runState = 'paused_auth';
                    $failures[] = ['code' => 'auth_required', 'visibility' => 'teacher', 'message' => 'Provider authentication required on local worker device.'];
                } elseif ($tasks->contains(fn($t) => $t->status === 'paused_quota') || $effects->contains(fn($e) => $e->status === 'rate_limited')) {
                    $runState = 'paused_quota';
                    $failures[] = ['code' => 'quota_exceeded', 'visibility' => 'teacher', 'message' => 'Provider rate limit or quota exceeded.'];
                } elseif ($sceneReturnTask?->status === 'awaiting_pull'
                    || (!$sceneReturnTask && ($localJob->status === 'awaiting_generation' || $run->state === 'awaiting_generation'))) {
                    $runState = 'awaiting_generation';
                } elseif ($localJob->status === 'paused' || $run->state === 'paused' || $tasks->contains(fn($t) => $t->status === 'paused')) {
                    $runState = 'paused';
                } elseif ($localJob->status === 'completed' || $run->state === 'completed') {
                    $runState = 'completed';
                } elseif ($localJob->status === 'failed' || $run->state === 'failed') {
                    $runState = 'failed';
                } elseif ($localJob->status === 'cancelled' || $run->state === 'cancelled') {
                    $runState = 'cancelled';
                } else {
                    $activeTask = $tasks->first(fn($t) => $t->status === 'leased');
                    if ($activeTask && $activeTask->active_assignment_id) {
                        $assignment = LocalAssignment::find($activeTask->active_assignment_id);
                        if ($assignment && $assignment->status === 'active') {
                            if (now()->greaterThan($assignment->lease_expires_at)) {
                                $runState = 'offline';
                                $failures[] = ['code' => 'worker_offline', 'visibility' => 'teacher', 'message' => 'Local worker lease expired and worker is offline.'];
                            } else {
                                $runState = 'running';
                            }
                        } else {
                            $runState = 'queued';
                        }
                    } else {
                        $queuedTask = $tasks->first(fn($t) => $t->status === 'queued');
                        $fencing = app(\App\Services\V3\LocalWorker\LocalWorkerFencingService::class);
                        $hasActiveDevice = $queuedTask !== null && ProcessingDevice::where('status', 'active')
                            ->where(function ($q) {
                                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            })
                            ->get()
                            ->contains(function (ProcessingDevice $device) use ($fencing, $localJob, $queuedTask) {
                                try {
                                    $fencing->verifyDeviceGrant($device, $localJob);
                                    $fencing->verifyTaskCapability($device, $queuedTask);
                                    return true;
                                } catch (\App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException) {
                                    return false;
                                }
                            });

                        if (!$hasActiveDevice) {
                            $runState = 'offline';
                            $failures[] = ['code' => 'worker_offline', 'visibility' => 'teacher', 'message' => 'No active local worker device enrolled.'];
                        } else {
                            $runState = 'queued';
                        }
                    }
                }

                $activeTask = $tasks->first(fn($t) => in_array($t->status, ['leased', 'queued', 'paused', 'reconciliation_required', 'paused_auth', 'paused_quota'], true));
                $workflow = [
                    'mode' => 'local_worker_v1',
                    'output_scope' => $outputScope,
                    'job_status' => $localJob->status,
                    'phase' => $activeTask ? ($activeTask->task_kind === 'author_prompts' ? 'authoring' : 'scene_return') : ($runState === 'awaiting_generation' ? 'awaiting_generation' : ($runState === 'completed' ? 'completed' : 'idle')),
                    'task_kind' => $activeTask?->task_kind,
                    'task_status' => $activeTask?->status,
                    'scene_return_status' => $sceneReturnTask?->status,
                    'scene_return_epoch' => $sceneReturnTask?->active_epoch,
                    'active_assignment_id' => $activeTask?->active_assignment_id,
                    'current_pdf_ordinal' => null,
                    'current_lesson_ordinal' => null,
                    'paused' => in_array($runState, ['paused', 'paused_auth', 'paused_quota'], true),
                    'cancelled' => $runState === 'cancelled',
                ];
                $authorTask = $tasks->firstWhere('task_kind', 'author_prompts');
                $reported = $authorTask ? \Illuminate\Support\Facades\Cache::get('local-worker-progress:'.$authorTask->id) : null;
                if ($reported && (int) $reported['assignment_epoch'] === (int) $authorTask->active_epoch) {
                    $lessonsSaved = max($lessonsSaved, $reported['lessons_saved']);
                    $scenesGenerated = max($scenesGenerated, $reported['scenes_generated']);
                    $scenesBuilt = max($scenesBuilt, $reported['scenes_built']);
                    $workflow['worker_progress'] = $reported;
                    $workflow['current_pdf_ordinal'] = $reported['current_pdf_ordinal'];
                    $fresh = abs(now()->diffInSeconds(Carbon::parse($reported['received_at']))) <= 120;
                    if ($fresh && $runState === 'running') $workflow['phase'] = $reported['phase'];
                    $workflow['progress_fresh'] = $fresh;
                    if (Carbon::parse($reported['received_at'])->gt($lastUpdated)) {
                        $lastUpdated = Carbon::parse($reported['received_at']);
                        $ageSeconds = abs((int) now()->diffInSeconds($lastUpdated));
                    }
                }

            }
        } else {
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
                    $candidateTotal = $candidate['lessons_total'] ?? $candidate['total_lessons'] ?? null;
                    if (is_numeric($candidateTotal) && (int) $candidateTotal >= $lessonsSaved) {
                        $knownLessonTotal = (int) $candidateTotal;
                    }
                    $workflow = [
                        'phase' => $candidate['phase'] ?? null,
                        'generation_phase' => $candidate['generation_phase'] ?? null,
                        'current_pdf_ordinal' => $candidate['current_pdf_ordinal'] ?? null,
                        'current_lesson_ordinal' => $candidate['current_lesson_ordinal'] ?? $candidate['lesson_ordinal'] ?? null,
                        'paused' => (bool) ($candidate['paused'] ?? false),
                        'cancelled' => (bool) ($candidate['cancelled'] ?? false),
                    ];
                    $failures = is_array($candidate['failures'] ?? null) ? $candidate['failures'] : [];
                }
                $terminalFailure = $statusResponse['failure'] ?? null;
                if (is_array($statusResponse['failures'] ?? null)) {
                    $failures = array_merge($failures, $statusResponse['failures']);
                }
                if (is_array($terminalFailure) && ($terminalFailure['visibility'] ?? null) === 'admin') {
                    $failures[] = ['code' => 'system_error', 'visibility' => 'admin'];
                }
            } catch (\Throwable) {
                // Keep the last persisted state and label its age below.
            }
        }

        // The run's total_lessons field can be a running saved-count on live
        // web runs. Only expose it as a denominator once the run is terminal.
        if ($knownLessonTotal === null && $runState === 'completed') {
            $knownLessonTotal = max($lessonsSaved, (int) ($run->total_lessons ?? 0));
        }
        if (! empty($caseIds)) {
            $caseOrder = [];
            foreach ($run->items->sortBy('pdf_ordinal') as $runItem) {
                if ($runItem->case_id) $caseOrder[$runItem->case_id] = (int) $runItem->pdf_ordinal;
            }
            $completedLessons = DB::table('v3_notebook_lesson_bundles as bundle')
                ->join('v3_lesson_nodes as node', 'node.id', '=', 'bundle.work_node_id')
                ->whereIn('bundle.case_id', $caseIds)
                ->orderBy('bundle.case_id')
                ->orderBy('bundle.work_ordinal')
                ->get([
                    'bundle.id', 'bundle.case_id', 'bundle.work_ordinal',
                    'bundle.work_node_id', 'node.title',
                ])
                ->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'title' => trim(str_replace(['**', '__'], '', (string) $lesson->title)),
                    'pdf_ordinal' => $caseOrder[$lesson->case_id] ?? null,
                    'ordinal' => (int) $lesson->work_ordinal,
                    'work_node_id' => $lesson->work_node_id,
                ])
                ->sortBy(fn ($lesson) => sprintf('%04d-%06d', $lesson['pdf_ordinal'] ?? 0, $lesson['ordinal']))
                ->values()
                ->all();

            // Older completed runs may predate durable lesson bundles. Keep
            // their existing persisted section projection available.
            if ($completedLessons === []) {
                $completedLessons = DB::table('v3_teacher_sections')
                    ->whereIn('case_id', $caseIds)
                    ->orderBy('created_at')->orderBy('ordinal_in_work')
                    ->get(['id', 'case_id', 'ordinal_in_work', 'section_identity'])
                    ->map(fn ($section) => [
                        'id' => $section->id,
                        'title' => $section->section_identity,
                        'pdf_ordinal' => $caseOrder[$section->case_id] ?? null,
                        'ordinal' => (int) $section->ordinal_in_work,
                    ])->all();
            }
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
            'output_scope' => $outputScope,
            'execution_mode' => $isLocalWorker ? 'local_worker_v1' : ($run->execution_mode ?? 'same_host'),
            'retry_available' => $isLocalWorker
                ? RunRetryEligibility::allows($run, $runState)
                : (RunRetryEligibility::allows($run, $runState)
                    || (bool) ($statusResponse['retry_available'] ?? false)),
            'workflow' => $workflow,
            'failures' => array_values(array_unique($failures, SORT_REGULAR)),
            'counts' => [
                'pdfs_total' => $pdfsTotal,
                'pdfs_accepted' => $pdfsAccepted,
                'pdfs_failed' => $pdfsFailed,
                'lessons_total' => $knownLessonTotal,
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
            'completed_lessons' => $completedLessons,
        ];
    }
}
