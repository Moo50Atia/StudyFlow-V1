<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SourceActivation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CaseSourceStatusController extends Controller
{
    public function show(Request $request, string $case): JsonResponse|View|Response
    {
        if (! Str::isUuid($case)) {
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

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);

        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
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

        $activation = SourceActivation::where('case_id', $caseModel->id)->first();
        $extracted = ExtractedSource::where('case_id', $caseModel->id)->first();
        $binding = KnowledgeSourceBinding::where('case_id', $caseModel->id)->first();

        $isDirect = ($caseModel->pipeline_version === 'notebooklm_combined_v1') || ($binding && $binding->source_mode === 'original_pdf');

        // State derivation: "pending" | "extracting" | "blocked" | "ingesting" | "uncertain" | "bound"
        $state = 'pending';
        $errorCode = null;

        if ($extracted && $extracted->status === 'blocked') {
            $state = 'blocked';
            $errorCode = $extracted->error_code;
        } elseif ($activation && $activation->state === 'blocked') {
            $state = 'blocked';
            $errorCode = $activation->error_code;
        } elseif ($binding && $binding->state === 'bound') {
            $state = 'bound';
        } elseif (($binding && in_array($binding->state, ['notebook_uncertain', 'add_uncertain'], true)) || ($activation && $activation->state === 'uncertain')) {
            $state = 'uncertain';
            $errorCode = $binding?->error_code ?? $activation?->error_code;
        } elseif ($binding || ($extracted && $extracted->status === 'ready')) {
            $state = 'ingesting';
        } elseif ($activation && in_array($activation->state, ['signaling', 'accepted'], true)) {
            $state = $isDirect ? 'ingesting' : 'extracting';
        }

        $pageCount = $extracted?->page_count ?? ($isDirect ? ($caseModel->document?->page_count ?? null) : null);
        $ocrPageCount = $extracted?->ocr_page_count ?? ($isDirect ? 0 : null);

        $gaps = [];
        if ($extracted && is_array($extracted->page_report)) {
            foreach ($extracted->page_report as $p) {
                if (! empty($p['gap_code'])) {
                    $gaps[] = [
                        'page' => (int) $p['page'],
                        'code' => (string) $p['gap_code'],
                    ];
                }
            }
        }

        $updatedAt = $extracted?->updated_at ?? $binding?->updated_at ?? $activation?->updated_at ?? $caseModel->updated_at;

        $payload = [
            'case_id' => $caseModel->id,
            'state' => $state,
            'page_count' => $pageCount,
            'ocr_page_count' => $ocrPageCount,
            'gaps' => $gaps,
            'error_code' => $errorCode,
            'updated_at' => $updatedAt->toISOString(),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $payload,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('v3.teacher.cases.source-status', [
            'case' => $caseModel,
            'sourceStatus' => $payload,
        ])->header('Cache-Control', 'private, no-store');
    }
}
