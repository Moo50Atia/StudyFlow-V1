<?php

namespace App\Services\V3\Review;

use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\TeacherReviewItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\V3\Workflow\BridgeRequestSigner;


class StartReviewPreview
{
    /**
     * Start a new containerized preview session for a review item.
     *
     * @return array{data?: array, error?: array{code: string, message: string}, status: int}
     */
    public function start(
        ProcessingCase $case,
        TeacherReviewItem $reviewItem,
        int $expectedReviewVersion,
        User $actor
    ): array {
        if ($reviewItem->case_id !== $case->id) {
            return [
                'error' => ['code' => 'resource_unavailable', 'message' => 'Review item not found.'],
                'status' => 404,
            ];
        }

        // Check review version
        if ((int) $reviewItem->state_version !== $expectedReviewVersion) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Review version mismatch.'],
                'status' => 409,
            ];
        }

        // Check review status: pending, dormant, or decided (if Section-current approval)
        if ($reviewItem->status === 'stale') {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Review item is stale.'],
                'status' => 409,
            ];
        }

        $secState = ReviewSectionState::where('section_id', $reviewItem->section_id)->first();
        if ($reviewItem->status === 'decided') {
            $isCurrentApproval = false;
            if ($secState && $secState->current_approval_id) {
                $approval = ApprovedScene::find($secState->current_approval_id);
                if ($approval && $approval->candidate_id === $reviewItem->candidate_id) {
                    $isCurrentApproval = true;
                }
            }
            if (! $isCurrentApproval) {
                return [
                    'error' => ['code' => 'review_conflict', 'message' => 'Review is decided and not current approval.'],
                    'status' => 409,
                ];
            }
        }

        // Recheck 012 qualified proof
        $candState = SceneCandidateState::where('candidate_id', $reviewItem->candidate_id)->first();
        if (! $candState || $candState->status !== 'qualified') {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Candidate is not qualified.'],
                'status' => 409,
            ];
        }

        // Read package from v3_private storage
        $candidate = $reviewItem->candidate;
        if (! $candidate) {
            return [
                'error' => ['code' => 'review_unavailable', 'message' => 'Candidate record missing.'],
                'status' => 503,
            ];
        }

        $disk = Storage::disk('v3_private');
        if (! $disk->exists($candidate->manifest_path)) {
            return [
                'error' => ['code' => 'review_unavailable', 'message' => 'Candidate manifest missing.'],
                'status' => 503,
            ];
        }

        $manifestContent = $disk->get($candidate->manifest_path);
        if (hash('sha256', $manifestContent) !== $candidate->manifest_sha256) {
            return [
                'error' => ['code' => 'review_unavailable', 'message' => 'Candidate manifest checksum mismatch.'],
                'status' => 503,
            ];
        }

        $manifest = json_decode($manifestContent, true);
        if (! is_array($manifest) || ! isset($manifest['files'])) {
            return [
                'error' => ['code' => 'review_unavailable', 'message' => 'Invalid manifest format.'],
                'status' => 503,
            ];
        }

        $files = [];
        $manifestDir = dirname($candidate->manifest_path);
        foreach ($manifest['files'] as $f) {
            $path = $f['path'] ?? '';
            $filePath = ($manifestDir === '.' || $manifestDir === '') ? $path : "{$manifestDir}/{$path}";
            if (! $disk->exists($filePath)) {
                return [
                    'error' => ['code' => 'review_unavailable', 'message' => "Candidate file {$path} missing."],
                    'status' => 503,
                ];
            }
            $content = $disk->get($filePath);
            if (hash('sha256', $content) !== ($f['sha256'] ?? '')) {
                return [
                    'error' => ['code' => 'review_unavailable', 'message' => "Candidate file {$path} corrupted."],
                    'status' => 503,
                ];
            }
            $files[] = [
                'path' => $path,
                'content' => $content,
            ];
        }

        $viewerRef = (string) Str::uuid();
        $bridgeUrl = config('services.v3.bridge_url', 'http://127.0.0.1:8765');
        $bridgePath = '/internal/v1/review-preview/sessions';
        $fullUrl = rtrim($bridgeUrl, '/') . $bridgePath;

        $bridgePayload = [
            'schema_version' => 1,
            'viewer_ref' => $viewerRef,
            'candidate_ref' => $candidate->id,
            'manifest_sha256' => $candidate->manifest_sha256,
            'files' => $files,
        ];

        $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $bridgePath, $bridgePayload);

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

        if ($resp->status() !== 200 && $resp->status() !== 201) {
            $err = $resp->json('error') ?? ['code' => 'review_unavailable', 'message' => 'Bridge preview start failed.'];
            return [
                'error' => $err,
                'status' => $resp->status() ?: 503,
            ];
        }

        $responseData = $resp->json('data') ?? [];
        $actualViewerRef = $responseData['viewer_ref'] ?? $viewerRef;
        $frame = $responseData['frame'] ?? [];

        // Close any prior session for this review item
        ReviewPreviewSession::where('review_item_id', $reviewItem->id)
            ->whereNull('closed_at')
            ->update([
                'state' => 'closed',
                'closed_at' => now(),
            ]);

        $expiresAt = now()->addMinutes(30);
        $idleExpiresAt = now()->addMinutes(15);

        $session = ReviewPreviewSession::forceCreate([
            'id' => $actualViewerRef,
            'case_id' => $case->id,
            'section_id' => $reviewItem->section_id,
            'review_item_id' => $reviewItem->id,
            'candidate_id' => $candidate->id,
            'actor_user_id' => $actor->id,
            'worker_session_ref' => $actualViewerRef,
            'state' => 'ready',
            'first_frame_at' => null, // R12: strictly null on start!
            'last_frame_no' => 0,
            'expires_at' => $expiresAt,
            'idle_expires_at' => $idleExpiresAt,
        ]);

        Cache::put("v3_preview_meta_{$actualViewerRef}", $frame, 1800);

        return [
            'data' => [
                'viewer_ref' => $actualViewerRef,
                'state' => 'ready',
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            'status' => 201,
        ];
    }
}
