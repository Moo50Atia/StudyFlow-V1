<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\TeacherReviewItem;
use App\Services\V3\Review\ApplyPreviewAction;
use App\Services\V3\Review\StartReviewPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\V3\Workflow\BridgeRequestSigner;


class ReviewPreviewController extends Controller
{
    public function __construct(
        protected StartReviewPreview $startService,
        protected ApplyPreviewAction $actionService
    ) {}

    public function start(Request $request, string $case, string $review): JsonResponse|Response
    {
        $resolved = $this->resolveAndAuthorize($request, $case, $review);
        if ($resolved instanceof JsonResponse || $resolved instanceof Response) {
            return $resolved;
        }
        [$caseModel, $reviewItem] = $resolved;

        $expectedVersion = $request->input('expected_review_version');
        if (! is_int($expectedVersion) || $expectedVersion < 1) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'expected_review_version must be a positive integer.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $res = $this->startService->start(
            $caseModel,
            $reviewItem,
            $expectedVersion,
            $request->user()
        );

        if (isset($res['error'])) {
            return response()->json(['error' => $res['error']], $res['status'])
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $res['data']], $res['status'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $case, string $review, string $viewer): JsonResponse|Response
    {
        $resolved = $this->resolveAndAuthorize($request, $case, $review);
        if ($resolved instanceof JsonResponse || $resolved instanceof Response) {
            return $resolved;
        }
        [$caseModel, $reviewItem] = $resolved;

        if (! Str::isUuid($viewer)) {
            return $this->errorNotFound();
        }

        $session = ReviewPreviewSession::where('id', $viewer)
            ->where('case_id', $caseModel->id)
            ->where('review_item_id', $reviewItem->id)
            ->first();

        if (! $session || $session->state === 'closed' || $session->closed_at !== null) {
            return $this->errorNotFound();
        }

        if (now()->greaterThan($session->expires_at) || now()->greaterThan($session->idle_expires_at)) {
            $session->state = 'closed';
            $session->closed_at = now();
            $session->save();

            return $this->errorNotFound();
        }

        $meta = Cache::get("v3_preview_meta_{$viewer}", []);
        $domText = $meta['dom_text'] ?? '';
        $controls = $meta['controls'] ?? [];

        return response()->json([
            'data' => [
                'viewer_ref' => $session->id,
                'state' => $session->state,
                'frame_no' => $session->last_frame_no ?? 0,
                'dom_text' => $domText,
                'controls' => $controls,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    public function frame(Request $request, string $case, string $review, string $viewer, int $frame_no): JsonResponse|Response
    {
        $resolved = $this->resolveAndAuthorize($request, $case, $review);
        if ($resolved instanceof JsonResponse || $resolved instanceof Response) {
            return $resolved;
        }
        [$caseModel, $reviewItem] = $resolved;

        if (! Str::isUuid($viewer) || $frame_no < 0) {
            return $this->errorNotFound();
        }

        $session = ReviewPreviewSession::where('id', $viewer)
            ->where('case_id', $caseModel->id)
            ->where('review_item_id', $reviewItem->id)
            ->first();

        if (! $session || $session->state === 'closed' || $session->closed_at !== null) {
            return $this->errorNotFound();
        }

        if (now()->greaterThan($session->expires_at) || now()->greaterThan($session->idle_expires_at)) {
            $session->state = 'closed';
            $session->closed_at = now();
            $session->save();

            return $this->errorNotFound();
        }

        $bridgeUrl = config('services.v3.bridge_url', 'http://127.0.0.1:8765');
        $bridgePath = "/internal/v1/review-preview/sessions/{$viewer}/frames/{$frame_no}";
        $fullUrl = rtrim($bridgeUrl, '/') . $bridgePath;
        $headers = BridgeRequestSigner::signBridgeRequest('GET', $bridgePath, []);

        try {
            $resp = Http::withHeaders($headers)
                ->timeout(15)
                ->get($fullUrl);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'review_unavailable', 'message' => $e->getMessage()],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        if ($resp->status() === 404) {
            return $this->errorNotFound();
        }

        if ($resp->status() !== 200) {
            return response()->json([
                'error' => ['code' => 'review_unavailable', 'message' => 'Bridge frame request failed.'],
            ], $resp->status() ?: 503)->header('Cache-Control', 'private, no-store');
        }

        $pngBytes = $resp->body();
        if (! str_starts_with($pngBytes, "\x89PNG\r\n\x1a\n") || strlen($pngBytes) > 2097152) {
            return response()->json([
                'error' => ['code' => 'review_unavailable', 'message' => 'Frame format invalid.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        // R12: Record first_frame_at ONLY after outward GET of frame 0 succeeds!
        if ($frame_no === 0 && $session->first_frame_at === null) {
            $session->first_frame_at = now();
            $session->save();
        }

        $session->idle_expires_at = now()->addMinutes(15);
        $session->save();

        return response($pngBytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function action(Request $request, string $case, string $review, string $viewer): JsonResponse|Response
    {
        $resolved = $this->resolveAndAuthorize($request, $case, $review);
        if ($resolved instanceof JsonResponse || $resolved instanceof Response) {
            return $resolved;
        }
        [$caseModel, $reviewItem] = $resolved;

        if (! Str::isUuid($viewer)) {
            return $this->errorNotFound();
        }

        $res = $this->actionService->apply(
            $caseModel,
            $reviewItem,
            $viewer,
            $request->all(),
            $request->user()
        );

        if (isset($res['error'])) {
            return response()->json(['error' => $res['error']], $res['status'])
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $res['data']], $res['status'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function close(Request $request, string $case, string $review, string $viewer): JsonResponse|Response
    {
        $resolved = $this->resolveAndAuthorize($request, $case, $review);
        if ($resolved instanceof JsonResponse || $resolved instanceof Response) {
            return $resolved;
        }
        [$caseModel, $reviewItem] = $resolved;

        if (! Str::isUuid($viewer)) {
            return $this->errorNotFound();
        }

        $session = ReviewPreviewSession::where('id', $viewer)
            ->where('case_id', $caseModel->id)
            ->where('review_item_id', $reviewItem->id)
            ->first();

        if ($session) {
            $session->state = 'closed';
            $session->closed_at = now();
            $session->save();

            $bridgeUrl = config('services.v3.bridge_url', 'http://127.0.0.1:8765');
            $bridgePath = "/internal/v1/review-preview/sessions/{$viewer}";
            $fullUrl = rtrim($bridgeUrl, '/') . $bridgePath;
            $headers = BridgeRequestSigner::signBridgeRequest('DELETE', $bridgePath, []);

            try {
                Http::withHeaders($headers)->timeout(5)->delete($fullUrl);
            } catch (\Throwable) {}

            Cache::forget("v3_preview_meta_{$viewer}");
        }

        return response()->noContent(204)->header('Cache-Control', 'private, no-store');
    }

    protected function resolveAndAuthorize(Request $request, string $case, ?string $review = null): array|JsonResponse|Response
    {
        if (! Str::isUuid($case) || ($review && ! Str::isUuid($review))) {
            return $this->errorNotFound();
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);
        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            return $this->errorNotFound();
        }

        $reviewItem = null;
        if ($review) {
            $reviewItem = TeacherReviewItem::where('id', $review)->where('case_id', $caseModel->id)->first();
            if (! $reviewItem) {
                return $this->errorNotFound();
            }
        }

        return [$caseModel, $reviewItem];
    }

    protected function errorNotFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'resource_unavailable',
                'message' => 'V3 access is unavailable.',
            ],
        ], 404)->header('Cache-Control', 'private, no-store');
    }
}
