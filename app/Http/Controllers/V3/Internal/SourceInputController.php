<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\Document;
use App\Models\V3\SourceActivation;
use App\Services\V3\Intake\AcceptedPdfReader;
use App\Services\V3\Settings\SettingsUnavailable;
use App\Services\V3\Settings\SettingsVersionRepository;
use Illuminate\Http\Request;

class SourceInputController extends Controller
{
    public function __construct(
        protected SettingsVersionRepository $settingsRepo,
        protected AcceptedPdfReader $pdfReader,
    ) {}

    public function streamPdf(string $activationId, Request $request)
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

        if (! in_array($activation->state, ['signaling', 'uncertain', 'accepted'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $case = $activation->processingCase;
        // workflow_started leaves the case queued; the immediately following
        // extraction:started event moves it to running before the worker fetches
        // the accepted PDF. Permit both active states so that normal Temporal
        // execution and its bounded activity retries can read the same source.
        if (! $case || ! in_array($case->state, ['queued', 'running'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        // Verify captured settings
        try {
            $settings = $this->settingsRepo->getCaptured((int) $case->settings_version_id);
        } catch (SettingsUnavailable $e) {
            return response()->json([
                'error' => [
                    'code' => 'settings_unavailable',
                    'message' => 'Captured settings are unavailable.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'settings_unavailable',
                    'message' => 'Captured settings are unavailable.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        // Open verified PDF stream
        try {
            $stream = $this->pdfReader->openForCase($case);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'source_integrity_failure',
                    'message' => 'Source integrity check failed.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        $document = Document::find($case->document_id);
        if (! $document) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $payload = is_array($settings->payload) ? $settings->payload : json_decode($settings->payload, true);
        $maxOcrPages = $payload['limits']['max_ocr_pages'] ?? 20;
        $maxPdfBytes = $payload['limits']['max_pdf_bytes'] ?? 10485760;

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $document->byte_size,
            'Cache-Control' => 'private, no-store',
            'X-V3-Case-ID' => $case->id,
            'X-V3-Document-SHA256' => $document->sha256,
            'X-V3-Page-Count' => (string) $document->page_count,
            'X-V3-Max-OCR-Pages' => (string) $maxOcrPages,
            'X-V3-Max-PDF-Bytes' => (string) $maxPdfBytes,
            'X-V3-Settings-Version-ID' => (string) $settings->id,
        ];

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $headers);
    }

    public function streamSteps(string $activationId, Request $request)
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

        if (! in_array($activation->state, ['signaling', 'uncertain', 'accepted'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $case = $activation->processingCase;
        if (! $case || ! in_array($case->state, ['queued', 'running'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        if ($case->pipeline_version !== 'notebooklm_combined_v1') {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Steps framework is only available for direct protocol cases.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $framework = $case->frameworkVersion ?? \App\Models\V3\NotebookFrameworkVersion::find($case->notebook_framework_version_id);
        if (! $framework) {
            return response()->json([
                'error' => [
                    'code' => 'framework_unavailable',
                    'message' => 'Framework version is unavailable.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('v3_private');
        $content = null;
        if ($disk->exists($framework->body_path)) {
            $content = $disk->get($framework->body_path);
        } elseif (file_exists(base_path('steps.txt')) && hash('sha256', file_get_contents(base_path('steps.txt'))) === $framework->body_sha256) {
            $content = file_get_contents(base_path('steps.txt'));
        }

        if ($content === null || hash('sha256', $content) !== $framework->body_sha256) {
            return response()->json([
                'error' => [
                    'code' => 'framework_unavailable',
                    'message' => 'Framework body integrity check failed.',
                ],
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'private, no-store',
            'X-V3-Framework-SHA256' => $framework->body_sha256,
        ];

        return response($content, 200, $headers);
    }
}
