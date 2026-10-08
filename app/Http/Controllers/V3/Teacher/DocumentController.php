<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function download(Request $request, string $document): JsonResponse|StreamedResponse
    {
        // 1. Validate UUID format
        if (! Str::isUuid($document)) {
            return $this->resourceUnavailable();
        }

        // 2. Resolve owned Document to preserve 404 missing/foreign equivalence
        $doc = Document::where('id', $document)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        if (! $doc) {
            return $this->resourceUnavailable();
        }

        // 3. Check ready state
        if ($doc->state !== 'ready') {
            return response()->json([
                'error' => [
                    'code' => 'document_not_ready',
                    'message' => 'Document is not in ready state.',
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        }

        // 4. Verify private storage file
        $disk = Storage::disk('v3_private');
        if (! $disk->exists($doc->storage_path)) {
            return response()->json([
                'error' => [
                    'code' => 'storage_unavailable',
                    'message' => 'Accepted document file is unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        $stream = $disk->readStream($doc->storage_path);
        if (! is_resource($stream)) {
            return response()->json([
                'error' => [
                    'code' => 'storage_unavailable',
                    'message' => 'Accepted document file stream is unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);
        $actualSha = hash_final($ctx);
        fclose($stream);

        if ($actualSha !== $doc->sha256) {
            return response()->json([
                'error' => [
                    'code' => 'storage_unavailable',
                    'message' => 'Accepted document file checksum mismatch.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        // 5. Stream byte response with strict security headers
        return response()->stream(function () use ($disk, $doc) {
            $readStream = $disk->readStream($doc->storage_path);
            fpassthru($readStream);
            if (is_resource($readStream)) {
                fclose($readStream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="source.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function resourceUnavailable(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'resource_unavailable',
                'message' => 'V3 resource is unavailable.',
            ],
        ], 404)->header('Cache-Control', 'private, no-store');
    }
}
