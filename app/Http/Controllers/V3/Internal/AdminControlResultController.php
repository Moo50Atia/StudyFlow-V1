<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\AdminCommand;
use App\Models\V3\AdminCommandDelivery;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseEvent;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminControlResultController extends Controller
{
    public function recordResult(Request $request): JsonResponse
    {
        $body = $request->all();

        if (
            ($body['schema_version'] ?? null) !== 1 ||
            ! isset($body['case_id'], $body['workflow_id'], $body['command_ref'], $body['control_version'], $body['outcome']) ||
            ! Str::isUuid($body['case_id']) ||
            ! Str::isUuid($body['command_ref']) ||
            ! in_array($body['outcome'], ['held', 'resumed', 'cancelled'], true)
        ) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid admin control result payload.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $caseId = $body['case_id'];
        $workflowId = $body['workflow_id'];
        $commandRef = $body['command_ref'];
        $controlVersion = (int) $body['control_version'];
        $outcome = $body['outcome'];
        $runId = $body['run_id'] ?? null;

        $command = AdminCommand::find($commandRef);
        if (! $command || $command->case_id !== $caseId) {
            return response()->json([
                'error' => [
                    'code' => 'resource_unavailable',
                    'message' => 'V3 resource is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $delivery = AdminCommandDelivery::where('command_id', $commandRef)
            ->where('workflow_id', $workflowId)
            ->first();

        if (! $delivery) {
            return response()->json([
                'error' => [
                    'code' => 'resource_unavailable',
                    'message' => 'V3 resource is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        if ($delivery->state === 'acknowledged') {
            return response()->json([
                'data' => [
                    'command_ref' => $commandRef,
                    'status' => 'acknowledged',
                ],
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        DB::transaction(function () use ($caseId, $command, $delivery, $controlVersion, $outcome, $runId, $workflowId) {
            $delivery->state = 'acknowledged';
            $delivery->acknowledged_at = now();
            if ($runId) {
                $delivery->run_id = $runId;
            }
            $delivery->save();

            $pendingDeliveriesCount = AdminCommandDelivery::where('command_id', $command->id)
                ->whereNotIn('state', ['acknowledged', 'not_started'])
                ->count();

            if ($pendingDeliveriesCount === 0) {
                $command->status = 'applied';
                $command->applied_at = now();
                $command->save();

                $control = CaseOperationControl::where('case_id', $caseId)->lockForUpdate()->first();
                $oldMode = $control ? $control->mode : 'normal';

                if ($outcome === 'held') {
                    if ($control) {
                        $control->update([
                            'mode' => 'held',
                            'applied_at' => now(),
                        ]);
                    }
                } elseif ($outcome === 'resumed') {
                    if ($control) {
                        $control->update([
                            'mode' => 'normal',
                            'applied_at' => now(),
                        ]);
                    }
                } elseif ($outcome === 'cancelled') {
                    if ($control) {
                        $control->update([
                            'mode' => 'cancelled',
                            'applied_at' => now(),
                        ]);
                    }

                    $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
                    if ($case) {
                        $newSequence = (int) $case->state_version + 1;
                        $case->state = 'cancelled';
                        $case->state_version = $newSequence;
                        $case->save();

                        CaseEvent::forceCreate([
                            'id' => (string) Str::uuid(),
                            'case_id' => $caseId,
                            'event_key' => "control:{$command->id}:cancelled",
                            'payload_digest' => hash('sha256', "control:{$command->id}:cancelled"),
                            'sequence' => $newSequence,
                            'workflow_id' => $workflowId ?: "v3-case-{$caseId}",
                            'run_id' => $runId ?: "v3-run-{$caseId}",
                            'event_type' => 'stage_completed',
                            'stage_code' => 'complete',
                            'status_code' => 'cancelled',
                            'error_code' => null,
                            'recorded_at' => now(),
                        ]);
                    }

                    TeacherReviewItem::where('case_id', $caseId)
                        ->whereIn('status', ['pending', 'dormant'])
                        ->update(['status' => 'stale']);

                    ReviewSectionState::where('case_id', $caseId)
                        ->update([
                            'current_review_item_id' => null,
                            'current_approval_id' => null,
                            'state' => 'blocked',
                            'error_code' => null,
                        ]);

                    WorkflowLink::where('case_id', $caseId)
                        ->update([
                            'approved_scene_count' => 0,
                            'status_code' => 'cancelled',
                        ]);
                }

                AdminOperationAudit::create([
                    'id' => (string) Str::uuid(),
                    'case_id' => $caseId,
                    'actor_user_id' => $command->actor_user_id,
                    'source_kind' => 'command',
                    'source_ref' => $command->id,
                    'event' => 'command_applied',
                    'action_code' => $command->action,
                    'reason_code' => $command->reason_code,
                    'old_control_mode' => $oldMode,
                    'new_control_mode' => ($outcome === 'resumed' ? 'normal' : $outcome),
                    'old_control_version' => $control?->control_version,
                    'new_control_version' => $control?->control_version,
                    'occurred_at' => now(),
                ]);
            }
        });

        return response()->json([
            'data' => [
                'command_ref' => $commandRef,
                'status' => 'acknowledged',
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }
}
