<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\TeacherReviewItem;
use App\Services\V3\Review\EnsureReviewReady;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewReadyController extends Controller
{
    public function __construct(
        protected EnsureReviewReady $ensureReviewReady
    ) {}

    public function ready(Request $request, string $case_id): JsonResponse
    {
        $validated = $request->validate([
            'schema_version' => 'required|integer|in:1',
            'candidate_ref' => 'required|uuid',
        ]);

        $result = $this->ensureReviewReady->ensure($case_id, $validated['candidate_ref']);

        if ($result['status'] === 'unavailable') {
            return response()->json([
                'error' => [
                    'code' => 'review_unavailable',
                    'message' => 'Review item cannot be created or resolved.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $statusCode = ($result['status'] === 'created') ? 201 : 200;

        return response()->json([
            'data' => $result['data'],
        ], $statusCode, ['Cache-Control' => 'private, no-store']);
    }

    public function current(Request $request, string $case_id): JsonResponse
    {
        $items = TeacherReviewItem::where('v3_teacher_review_items.case_id', $case_id)
            ->whereIn('status', ['pending', 'dormant'])
            ->join('v3_teacher_sections', 'v3_teacher_review_items.section_id', '=', 'v3_teacher_sections.id')
            ->orderBy('v3_teacher_sections.ordinal_in_work')
            ->select('v3_teacher_review_items.*')
            ->get();

        $data = $items->map(fn ($item) => [
            'review_ref' => $item->id,
            'section_ref' => $item->section_id,
            'candidate_ref' => $item->candidate_id,
            'candidate_version' => $item->candidate_version,
            'qualification_ref' => $item->qualification_id,
        ]);

        return response()->json([
            'data' => $data,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
