<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\LocalWorkerFencingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskInputController extends Controller
{
    public function __construct(
        protected LocalWorkerFencingService $fencingService
    ) {}

    private function verifyDeviceAccess(Request $request, string $taskId): array
    {
        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('local_worker_device');

        $task = LocalTask::find($taskId);
        if (!$task) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Task not found.']], 404));
        }

        $job = LocalJob::find($task->job_id);
        if (!$job) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Task not found.']], 404));
        }

        try {
            $this->fencingService->verifyDeviceGrant($device, $job);
            // Input material is available only while this device still holds the live,
            // current assignment. Both headers are mandatory to prevent stale-device reads.
            $assignment = $this->fencingService->verifyAssignmentFencing($request, $task, $device, false, true);
        } catch (\App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException $e) {
            abort(response()->json([
                'error' => ['code' => $e->getProtocolCode(), 'message' => $e->getMessage()],
            ], $e->getStatusCode()));
        }

        return [$task, $assignment];
    }

    /**
     * Stream exact sealed input manifest bytes.
     * GET /api/v3/local-worker/v1/tasks/{task}/input-manifest
     */
    public function inputManifest(Request $request, string $task): Response
    {
        [$localTask] = $this->verifyDeviceAccess($request, $task);

        if (!$localTask->input_manifest_id) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Manifest artifact missing.']], 404));
        }

        $manifestArtifact = LocalArtifact::find($localTask->input_manifest_id);
        if (!$manifestArtifact || !Storage::disk('v3_private')->exists($manifestArtifact->storage_path)) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Manifest storage missing.']], 404));
        }

        $content = Storage::disk('v3_private')->get($manifestArtifact->storage_path);

        return response($content, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Length' => strlen($content),
            'ETag' => '"' . $localTask->input_manifest_sha256 . '"',
            'Cache-Control' => 'private, no-transform',
        ]);
    }

    /**
     * Stream a manifest-listed input artifact with HTTP range support.
     * GET /api/v3/local-worker/v1/tasks/{task}/inputs/{artifact_id}
     */
    public function streamInput(Request $request, string $task, string $artifactId): Response|StreamedResponse
    {
        [$localTask] = $this->verifyDeviceAccess($request, $task);

        $artifact = LocalArtifact::where('id', $artifactId)
            ->where(function ($q) use ($localTask) {
                $q->where('job_id', $localTask->job_id)
                  ->orWhere('task_id', $localTask->id);
            })
            ->first();

        if (!$artifact || !Storage::disk('v3_private')->exists($artifact->storage_path)) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Artifact not found.']], 404));
        }

        $totalSize = Storage::disk('v3_private')->size($artifact->storage_path);
        $diskPath = Storage::disk('v3_private')->path($artifact->storage_path);
        $mediaType = $artifact->media_type ?: 'application/octet-stream';
        $etag = '"' . $artifact->digest_sha256 . '"';

        $range = $request->header('Range');
        if ($range && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $matches)) {
            $start = (int) $matches[1];
            $end = $matches[2] !== '' ? (int) $matches[2] : $totalSize - 1;

            if ($start > $end || $start >= $totalSize) {
                return response('', 416, [
                    'Content-Range' => "bytes */{$totalSize}",
                ]);
            }

            $length = $end - $start + 1;

            return new StreamedResponse(function () use ($diskPath, $start, $length) {
                $fp = fopen($diskPath, 'rb');
                fseek($fp, $start);
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $chunkSize = min(8192, $remaining);
                    $data = fread($fp, $chunkSize);
                    echo $data;
                    flush();
                    $remaining -= strlen($data);
                }
                fclose($fp);
            }, 206, [
                'Content-Type' => $mediaType,
                'Content-Length' => (string) $length,
                'Content-Range' => "bytes {$start}-{$end}/{$totalSize}",
                'Accept-Ranges' => 'bytes',
                'ETag' => $etag,
                'Cache-Control' => 'private, no-transform',
            ]);
        }

        return new StreamedResponse(function () use ($diskPath) {
            $fp = fopen($diskPath, 'rb');
            while (!feof($fp)) {
                echo fread($fp, 8192);
                flush();
            }
            fclose($fp);
        }, 200, [
            'Content-Type' => $mediaType,
            'Content-Length' => (string) $totalSize,
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Cache-Control' => 'private, no-transform',
        ]);
    }
}
