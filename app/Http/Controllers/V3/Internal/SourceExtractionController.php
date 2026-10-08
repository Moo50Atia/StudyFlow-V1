<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\ExtractedSource;
use App\Models\V3\SourceActivation;
use App\Services\V3\Source\SourceArtifactStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SourceExtractionController extends Controller
{
    public function __construct(
        protected SourceArtifactStore $artifactStore,
    ) {}

    public function getExtraction(string $activationId, Request $request)
    {
        $activation = SourceActivation::find($activationId);
        if (! $activation) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $source = ExtractedSource::where('activation_id', $activationId)->first();
        if (! $source) {
            return response()->json([
                'data' => [
                    'status' => 'absent',
                    'extracted_source_ref' => null,
                    'text_sha256' => null,
                    'page_count' => null,
                ],
            ], 200, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json([
            'data' => [
                'status' => $source->status,
                'extracted_source_ref' => $source->id,
                'text_sha256' => $source->text_sha256,
                'page_count' => $source->page_count,
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function storeExtraction(string $activationId, Request $request)
    {
        $activation = SourceActivation::find($activationId);
        if (! $activation) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $rawPayload = json_decode($request->getContent(), true) ?: [];
        $caseId = $rawPayload['case_id'] ?? $request->input('case_id');
        if ($caseId !== $activation->case_id) {
            return response()->json([
                'error' => [
                    'code' => 'source_conflict',
                    'message' => 'Source state changed.',
                ],
            ], 409, ['Cache-Control' => 'private, no-store']);
        }

        $status = $rawPayload['status'] ?? $request->input('status');
        if (! in_array($status, ['ready', 'blocked'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'source_invalid',
                    'message' => 'Source request is invalid.',
                ],
            ], 422, ['Cache-Control' => 'private, no-store']);
        }

        $text = $rawPayload['text'] ?? null;
        $textSha256 = $rawPayload['text_sha256'] ?? null;
        $pageReport = $rawPayload['page_report'] ?? [];
        $extractorVersion = (string) ($rawPayload['extractor_version'] ?? '0.1.0');

        if ($status === 'ready') {
            if ($text === null || ! is_string($text)) {
                return response()->json([
                    'error' => [
                        'code' => 'source_invalid',
                        'message' => 'Source request is invalid.',
                    ],
                ], 422, ['Cache-Control' => 'private, no-store']);
            }
            if (strlen($text) > 1048576) {
                return response()->json([
                    'error' => [
                        'code' => 'source_invalid',
                        'message' => 'Source request is invalid.',
                    ],
                ], 422, ['Cache-Control' => 'private, no-store']);
            }
            $calculatedSha = hash('sha256', $text);
            if ($calculatedSha !== $textSha256) {
                return response()->json([
                    'error' => [
                        'code' => 'source_invalid',
                        'message' => 'Source request is invalid.',
                    ],
                ], 422, ['Cache-Control' => 'private, no-store']);
            }
        } else {
            $text = null;
            $textSha256 = null;
        }

        // Check if row already exists for this case
        $existing = ExtractedSource::where('case_id', $caseId)->first();
        if ($existing) {
            $isExactReplay = ($existing->status === $status)
                && ($existing->text_sha256 === $textSha256)
                && ($existing->extractor_version === $extractorVersion);

            if ($isExactReplay) {
                return response()->json([
                    'data' => [
                        'extracted_source_ref' => $existing->id,
                        'status' => $existing->status,
                    ],
                ], 200, ['Cache-Control' => 'private, no-store']);
            }

            return response()->json([
                'error' => [
                    'code' => 'source_conflict',
                    'message' => 'Source state changed.',
                ],
            ], 409, ['Cache-Control' => 'private, no-store']);
        }

        $case = $activation->processingCase;
        $documentId = $case->document_id;
        $settingsVersionId = $case->settings_version_id;
        $pageCount = count($pageReport);
        $ocrPageCount = 0;
        foreach ($pageReport as $p) {
            if (($p['method'] ?? null) === 'ocr') {
                $ocrPageCount++;
            }
        }

        $artifactPath = null;
        $textBytes = 0;
        $errorCode = null;

        if ($status === 'ready') {
            try {
                $artifactPath = $this->artifactStore->putArtifact($case->id, $text, $textSha256);
                $textBytes = strlen($text);
            } catch (\Throwable $e) {
                return response()->json([
                    'error' => [
                        'code' => 'source_unavailable',
                        'message' => 'Source is unavailable.',
                    ],
                ], 503, ['Cache-Control' => 'private, no-store']);
            }
        } else {
            // Determine safe error code
            $errorCode = 'validation';
            foreach ($pageReport as $p) {
                $gap = $p['gap_code'] ?? null;
                if ($gap === 'parser_error') {
                    $errorCode = 'internal';
                    break;
                }
            }
        }

        $row = ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'activation_id' => $activation->id,
            'document_id' => $documentId,
            'settings_version_id' => $settingsVersionId,
            'status' => $status,
            'artifact_path' => $artifactPath,
            'text_sha256' => $textSha256,
            'text_bytes' => $textBytes,
            'page_count' => $pageCount,
            'ocr_page_count' => $ocrPageCount,
            'page_report' => $pageReport,
            'error_code' => $errorCode,
            'extractor_version' => $extractorVersion,
        ]);

        return response()->json([
            'data' => [
                'extracted_source_ref' => $row->id,
                'status' => $row->status,
            ],
        ], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function getText(string $activationId, string $extractedSourceRef, Request $request)
    {
        $activation = SourceActivation::find($activationId);
        if (! $activation) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $extractedSource = ExtractedSource::where('id', $extractedSourceRef)
            ->where('activation_id', $activationId)
            ->first();

        if (! $extractedSource || $extractedSource->status !== 'ready' || ! $extractedSource->artifact_path) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $content = $this->artifactStore->getArtifact($extractedSource->artifact_path, $extractedSource->text_sha256);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'source_integrity_failure',
                    'message' => 'Source integrity check failed.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
