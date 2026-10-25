<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\V3\CaseProgressResource;
use App\Models\V3\CaseEvent;
use App\Models\V3\ProcessingCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CaseProgressController extends Controller
{
    public function show(Request $request, string $case)
    {
        if (! Str::isUuid($case)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 resource is unavailable.');
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::with('workflowLink')->find($case);

        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 resource is unavailable.');
        }

        if ($request->has('page')) {
            $rawPage = (string) $request->query('page');
            if (! ctype_digit($rawPage) || (int) $rawPage < 1) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => [
                            'code' => 'validation_failed',
                            'message' => 'The page must be a positive integer.',
                        ],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }
                abort(422, 'The page must be a positive integer.');
            }
            $page = (int) $rawPage;
        } else {
            $page = 1;
        }

        $link = $caseModel->workflowLink;
        $stageCode = $link?->stage_code ?? 'intake';
        $statusCode = $link?->status_code ?? ($caseModel->state === 'awaiting_workflow' ? 'queued' : $caseModel->state);
        $errorCode = $link?->error_code ?? null;
        $stateVersion = (int) $caseModel->state_version;

        $isTerminal = in_array($statusCode, ['failed', 'complete', 'blocked'], true);
        if ($isTerminal) {
            $delayed = false;
        } else {
            $lastEventRecordedAt = CaseEvent::where('case_id', $caseModel->id)
                ->orderByDesc('sequence')
                ->value('recorded_at');

            $refTime = $lastEventRecordedAt ? Carbon::parse($lastEventRecordedAt) : $caseModel->created_at;
            $delayed = $refTime->lte(now()->subSeconds(300));
        }

        $perPage = 20;
        $eventsPaginator = CaseEvent::where('case_id', $caseModel->id)
            ->orderBy('sequence', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        $eventsList = collect($eventsPaginator->items())->map(function (CaseEvent $event) {
            return [
                'event_id' => $event->id,
                'sequence' => (int) $event->sequence,
                'stage_code' => $event->stage_code,
                'status_code' => $event->status_code,
                'error_code' => $event->error_code,
                'recorded_at' => $event->recorded_at->toISOString(),
            ];
        })->values()->all();

        $payload = [
            'case_id' => $caseModel->id,
            'stage_code' => $stageCode,
            'status_code' => $statusCode,
            'error_code' => $errorCode,
            'state_version' => $stateVersion,
            'delayed' => $delayed,
            'events' => $eventsList,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $eventsPaginator->hasMorePages(),
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $payload,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.teacher.cases.progress', [
            'case' => $caseModel,
            'progress' => $payload,
        ])->header('Cache-Control', 'private, no-store');
    }
}
