<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ApprovedScene;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Review\ReviewContext;
use App\Services\V3\Review\SubmitReviewDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SceneReviewController extends Controller
{
    public function __construct(
        protected ReviewContext $reviewContext,
        protected SubmitReviewDecision $decisionService
    ) {}

    public function index(Request $request, string $case): JsonResponse|View|Response
    {
        if (! Str::isUuid($case)) {
            return $this->errorNotFound($request);
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);
        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            return $this->errorNotFound($request);
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = 20;

        $query = ReviewSectionState::query()
            ->join('v3_teacher_review_items', 'v3_review_section_states.current_review_item_id', '=', 'v3_teacher_review_items.id')
            ->join('v3_teacher_sections', 'v3_teacher_review_items.section_id', '=', 'v3_teacher_sections.id')
            ->where('v3_review_section_states.case_id', $caseModel->id)
            ->whereNotNull('v3_review_section_states.current_review_item_id')
            ->orderBy('v3_teacher_sections.ordinal_in_work', 'asc')
            ->select([
                'v3_teacher_review_items.id as review_ref',
                'v3_teacher_review_items.section_id as section_ref',
                'v3_teacher_review_items.candidate_id as candidate_ref',
                'v3_teacher_review_items.candidate_version',
                'v3_teacher_review_items.status',
                'v3_teacher_review_items.ready_at',
                'v3_teacher_review_items.dormant_at',
            ]);

        $total = $query->count();
        $items = $query->forPage($page, $perPage)->get()->map(function ($row) {
            return [
                'review_ref' => $row->review_ref,
                'section_ref' => $row->section_ref,
                'candidate_ref' => $row->candidate_ref,
                'candidate_version' => (int) $row->candidate_version,
                'status' => $row->status,
                'ready_at' => $row->ready_at ? (string) $row->ready_at : null,
                'dormant_at' => $row->dormant_at ? (string) $row->dormant_at : null,
            ];
        })->values()->all();

        $payload = [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload, 200)->header('Cache-Control', 'no-store, private');
        }

        $reviewRefs = collect($items)->pluck('review_ref')->all();
        $reminders = \App\Models\V3\ReviewReminder::whereIn('review_item_id', $reviewRefs)
            ->where('state', 'posted')
            ->get();

        foreach ($reminders as $reminder) {
            if ($reminder->seen_at === null) {
                $reminder->update(['seen_at' => now()]);
            }
        }

        return response()->view('v3.teacher.reviews.index', [
            'reviews' => $payload,
            'case' => $caseModel,
            'reminders' => $reminders,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $case, string $review): JsonResponse|View|Response
    {
        if (! Str::isUuid($case) || ! Str::isUuid($review)) {
            return $this->errorNotFound($request);
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);
        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            return $this->errorNotFound($request);
        }

        $context = $this->reviewContext->resolve($caseModel, $review);
        if (! $context) {
            return $this->errorNotFound($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $context], 200)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('v3.teacher.reviews.show', [
            'data' => $context,
            'case' => $caseModel,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function approvedScenes(Request $request, string $case): JsonResponse|View|Response
    {
        if (! Str::isUuid($case)) {
            return $this->errorNotFound($request);
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);
        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            return $this->errorNotFound($request);
        }

        $query = ReviewSectionState::query()
            ->join('v3_approved_scenes', 'v3_review_section_states.current_approval_id', '=', 'v3_approved_scenes.id')
            ->join('v3_teacher_review_decisions', 'v3_approved_scenes.review_decision_id', '=', 'v3_teacher_review_decisions.id')
            ->join('v3_teacher_sections', 'v3_approved_scenes.section_id', '=', 'v3_teacher_sections.id')
            ->where('v3_review_section_states.case_id', $caseModel->id)
            ->whereNotNull('v3_review_section_states.current_approval_id')
            ->orderBy('v3_teacher_sections.ordinal_in_work', 'asc')
            ->select([
                'v3_approved_scenes.id as approval_ref',
                'v3_teacher_review_decisions.review_item_id as review_ref',
                'v3_approved_scenes.section_id as section_ref',
                'v3_approved_scenes.candidate_id as candidate_ref',
                'v3_approved_scenes.candidate_version',
                'v3_approved_scenes.approved_at',
            ]);

        $items = $query->get()->map(function ($row) {
            return [
                'approval_ref' => $row->approval_ref,
                'review_ref' => $row->review_ref,
                'section_ref' => $row->section_ref,
                'candidate_ref' => $row->candidate_ref,
                'candidate_version' => (int) $row->candidate_version,
                'approved_at' => (string) $row->approved_at,
            ];
        })->values()->all();

        $payload = ['data' => $items];

        if ($request->expectsJson()) {
            return response()->json($payload, 200)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('v3.teacher.reviews.approvals', [
            'approvals' => $payload,
            'case' => $caseModel,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function decide(Request $request, string $case, string $review): JsonResponse|RedirectResponse|Response
    {
        if (! Str::isUuid($case) || ! Str::isUuid($review)) {
            return $this->errorNotFound($request);
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);
        if (! $caseModel || ! Gate::forUser($request->user())->allows('update', $caseModel)) {
            return $this->errorNotFound($request);
        }

        $reviewItem = TeacherReviewItem::where('id', $review)->where('case_id', $caseModel->id)->first();
        if (! $reviewItem) {
            return $this->errorNotFound($request);
        }

        $res = $this->decisionService->submit(
            $caseModel,
            $reviewItem,
            $request->all(),
            $request->user()
        );

        if (isset($res['error'])) {
            return response()->json(['error' => $res['error']], $res['status'])
                ->header('Cache-Control', 'private, no-store');
        }

        if (! $request->expectsJson()) {
            return redirect()->to(route('v3.teacher.cases.reviews.index', ['case' => $caseModel->id]), 303)
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $res['data']], $res['status'])
            ->header('Cache-Control', 'private, no-store');
    }

    protected function errorNotFound(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'resource_unavailable',
                    'message' => 'V3 access is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        abort(404, 'V3 access is unavailable.');
    }
}
