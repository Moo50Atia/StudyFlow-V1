<?php

namespace App\Services\V3\Review;

use App\Models\User;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\TeacherReviewItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Services\V3\Workflow\BridgeRequestSigner;


class ApplyPreviewAction
{
    public const ALLOWED_KEYS = [
        'Tab', 'Shift+Tab', 'Enter', 'Space', 'Escape',
        'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
        'Backspace', 'Delete',
    ];

    /**
     * Apply an interactive action to a running preview session.
     *
     * @return array{data?: array, error?: array{code: string, message: string}, status: int}
     */
    public function apply(
        ProcessingCase $case,
        TeacherReviewItem $reviewItem,
        string $viewerRef,
        array $actionData,
        User $actor
    ): array {
        $session = ReviewPreviewSession::where('id', $viewerRef)
            ->where('case_id', $case->id)
            ->where('review_item_id', $reviewItem->id)
            ->first();

        if (! $session) {
            return [
                'error' => ['code' => 'resource_unavailable', 'message' => 'Preview session not found.'],
                'status' => 404,
            ];
        }

        if ($session->state === 'closed' || $session->closed_at !== null) {
            return [
                'error' => ['code' => 'resource_unavailable', 'message' => 'Preview session is closed.'],
                'status' => 404,
            ];
        }

        if (now()->greaterThan($session->expires_at) || now()->greaterThan($session->idle_expires_at)) {
            $session->state = 'closed';
            $session->closed_at = now();
            $session->save();

            return [
                'error' => ['code' => 'resource_unavailable', 'message' => 'Preview session expired.'],
                'status' => 404,
            ];
        }

        // Validate PreviewActionV1 contract
        $schemaVersion = $actionData['schema_version'] ?? null;
        $expectedFrameNo = $actionData['expected_frame_no'] ?? null;
        $kind = $actionData['kind'] ?? null;
        $payload = $actionData['payload'] ?? null;

        if ($schemaVersion !== 1 || ! is_int($expectedFrameNo) || $expectedFrameNo < 0 || ! is_string($kind) || ! is_array($payload)) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid action contract.'],
                'status' => 422,
            ];
        }

        if ($kind === 'click') {
            $x = $payload['x'] ?? null;
            $y = $payload['y'] ?? null;
            if (! is_int($x) || ! is_int($y) || $x < 0 || $x > 1279 || $y < 0 || $y > 719) {
                return [
                    'error' => ['code' => 'contract_invalid', 'message' => 'Invalid click coordinates.'],
                    'status' => 422,
                ];
            }
        } elseif ($kind === 'press') {
            $key = $payload['key'] ?? null;
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                return [
                    'error' => ['code' => 'contract_invalid', 'message' => 'Invalid key in press action.'],
                    'status' => 422,
                ];
            }
        } elseif ($kind === 'type') {
            $text = $payload['text'] ?? null;
            if (! is_string($text) || strlen($text) < 1 || strlen($text) > 256) {
                return [
                    'error' => ['code' => 'contract_invalid', 'message' => 'Invalid text in type action.'],
                    'status' => 422,
                ];
            }
        } else {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Unsupported action kind.'],
                'status' => 422,
            ];
        }

        $bridgeUrl = config('services.v3.bridge_url', 'http://127.0.0.1:8765');
        $bridgePath = "/internal/v1/review-preview/sessions/{$viewerRef}/actions";
        $fullUrl = rtrim($bridgeUrl, '/') . $bridgePath;

        $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $bridgePath, $actionData);

        try {
            $resp = Http::withHeaders($signed['headers'])
                ->timeout(15)
                ->withBody($signed['body'], 'application/json')
                ->post($fullUrl);
        } catch (\Throwable $e) {
            return [
                'error' => ['code' => 'review_unavailable', 'message' => $e->getMessage()],
                'status' => 503,
            ];
        }

        if ($resp->status() === 409) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Action conflict or stale frame.'],
                'status' => 409,
            ];
        }

        if ($resp->status() === 404) {
            return [
                'error' => ['code' => 'resource_unavailable', 'message' => 'Session closed or expired on bridge.'],
                'status' => 404,
            ];
        }

        if ($resp->status() !== 200) {
            return [
                'error' => ['code' => 'review_unavailable', 'message' => 'Bridge action execution failed.'],
                'status' => $resp->status() ?: 503,
            ];
        }

        $responseData = $resp->json('data') ?? [];
        $newFrameNo = $responseData['frame_no'] ?? ($session->last_frame_no + 1);
        $frame = $responseData['frame'] ?? [];

        $session->last_frame_no = $newFrameNo;
        $session->last_action_at = now();
        $session->idle_expires_at = now()->addMinutes(15);
        $session->save();

        Cache::put("v3_preview_meta_{$viewerRef}", $frame, 1800);

        return [
            'data' => [
                'viewer_ref' => $viewerRef,
                'frame_no' => $newFrameNo,
                'dom_text' => $frame['dom_text'] ?? '',
                'controls' => $frame['controls'] ?? [],
            ],
            'status' => 200,
        ];
    }
}
