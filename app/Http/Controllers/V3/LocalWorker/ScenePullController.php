<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\PipelineCommand;
use App\Models\V3\SubjectRun;
use App\Services\LearningAccess;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScenePullController extends Controller
{
    public function __invoke(Request $request, SubjectRun $run)
    {
        $actor = $request->user();
        abort_unless(app(LearningAccess::class)->isOwner($actor) || $run->owner_user_id === $actor->id, 404);
        $data = $request->validate(['request_id' => ['required', 'uuid']]);

        $receipt = DB::transaction(function () use ($actor, $run, $data) {
            $run = SubjectRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $digest = hash('sha256', 'pull_scenes:' . $run->id . ':' . $actor->id);
            $existing = PipelineCommand::whereKey($data['request_id'])->first();
            if ($existing) {
                if ($existing->run_id !== $run->id || $existing->request_sha256 !== $digest) {
                    throw new LocalWorkerProtocolException('Pull request ID belongs to another request.', 'idempotency_conflict', 409);
                }
                return ['run_id' => $run->id, 'request_id' => $existing->id, 'status' => 'requested',
                    'return_task_epoch' => $existing->expected_version];
            }
            if ($run->execution_mode !== 'local_worker_v1' || in_array($run->state, ['paused', 'cancelled', 'failed'], true)) {
                throw new LocalWorkerProtocolException('This run is not available for scene Pull.', 'invalid_task_status', 409);
            }
            $job = LocalJob::where('run_id', $run->id)->lockForUpdate()->firstOrFail();
            $task = LocalTask::where('job_id', $job->id)->where('task_kind', 'return_scenes')->lockForUpdate()->first();
            if (!$task || !in_array($task->status, ['awaiting_pull', 'queued', 'leased', 'running', 'completed'], true)) {
                throw new LocalWorkerProtocolException('Authoring must finish before scene Pull can be requested.', 'scene_pull_unavailable', 409);
            }
            if ($task->status === 'awaiting_pull') {
                $task->status = 'queued';
                $task->save();
            }
            PipelineCommand::create([
                'id' => $data['request_id'], 'run_id' => $run->id, 'actor_user_id' => $actor->id,
                'action' => 'pull_scenes', 'request_sha256' => $digest,
                'expected_version' => $task->active_epoch, 'state' => 'applied', 'accepted_at' => now(),
            ]);
            return ['run_id' => $run->id, 'request_id' => $data['request_id'], 'status' => $task->status,
                'return_task_epoch' => $task->active_epoch];
        });

        if (!$request->expectsJson()) {
            return back()->with('status', 'Scene Pull requested. The local worker will upload the run files when connected.');
        }
        return response()->json($receipt, 202)->header('Cache-Control', 'private, no-store');
    }
}
