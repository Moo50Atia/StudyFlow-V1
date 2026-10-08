<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Workflow\ProjectCaseEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseEventController extends Controller
{
    public function __construct(
        protected ProjectCaseEvent $projector
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $payload = $request->json()->all();
            if (empty($payload)) {
                $payload = json_decode($request->getContent(), true) ?? [];
            }

            $result = $this->projector->project($payload);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => $e->getMessage(),
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => 'Internal processing error.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        $statusCode = $result['status'];
        if (isset($result['error'])) {
            return response()->json([
                'error' => $result['error'],
            ], $statusCode)->header('Cache-Control', 'private, no-store');
        }

        $event = $result['event'];
        $case = $result['case'];

        return response()->json([
            'data' => [
                'event_id' => $event->id,
                'case_id' => $case->id,
                'event_key' => $event->event_key,
                'state_version' => $event->sequence,
            ],
        ], $statusCode)->header('Cache-Control', 'private, no-store');
    }
}
