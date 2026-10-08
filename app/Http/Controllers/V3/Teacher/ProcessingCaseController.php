<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Intake\StartProcessingCase;
use App\Services\V3\Intake\ValidateCaseInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProcessingCaseController extends Controller
{
    public function __construct(
        protected ValidateCaseInput $validator,
        protected StartProcessingCase $starter
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse|Response
    {
        $documentId = $request->input('document_id');

        // 1. Resolve Document visibility first to preserve 404 missing/foreign equivalence
        if (! is_string($documentId) || ! Str::isUuid($documentId)) {
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

        $document = Document::where('id', $documentId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        if (! $document) {
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

        if ($document->state !== 'ready') {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'document_not_ready',
                        'message' => 'Document is not in ready state.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            }

            return response()->view('v3.teacher.cases.conflict', [
                'code' => 'document_not_ready',
                'message' => 'Document is not in ready state.',
            ], 409)->header('Cache-Control', 'private, no-store');
        }

        // 2. Validate application fields (action, idempotency_key, extra fields)
        $validation = $this->validator->validate($request);
        if (! $validation['valid']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $validation['reason_code'],
                        'message' => $validation['message'],
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            }

            return back()->withErrors(['case' => $validation['message']]);
        }

        // 3. Start or replay case
        $result = $this->starter->start(
            $request->user()->id,
            $document->id,
            $validation['action'],
            $validation['idempotency_key']
        );

        if (! $result['success']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $result['code'],
                        'message' => $result['message'],
                    ],
                ], $result['status'])->header('Cache-Control', 'private, no-store');
            }

            if ($result['status'] === 409) {
                return response()->view('v3.teacher.cases.conflict', [
                    'code' => $result['code'],
                    'message' => $result['message'],
                ], 409)->header('Cache-Control', 'private, no-store');
            }

            abort($result['status'], $result['message']);
        }

        $case = $result['case'];
        $receipt = $this->formatCaseReceipt($case);
        $statusCode = $result['is_replay'] ? 200 : 201;

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $receipt,
            ], $statusCode)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('v3.teacher.cases.show', $case->id, 303);
    }

    public function show(Request $request, string $case): JsonResponse|View
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

        $caseModel = ProcessingCase::find($case);

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

        $receipt = $this->formatCaseReceipt($caseModel);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $receipt,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        return view('v3.teacher.cases.show', [
            'case' => $caseModel,
            'receipt' => $receipt,
        ]);
    }

    protected function formatCaseReceipt(ProcessingCase $case): array
    {
        return [
            'case_id' => $case->id,
            'document_id' => $case->document_id,
            'state' => $case->state,
            'settings_version_id' => (int) $case->settings_version_id,
            'created_at' => $case->created_at->toISOString(),
        ];
    }
}
