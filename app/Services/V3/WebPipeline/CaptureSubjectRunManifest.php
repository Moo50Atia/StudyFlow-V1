<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineCommand;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CaptureSubjectRunManifest
{
    public const MAX_MANIFEST_BYTES = 32768; // 32 KiB

    /**
     * Capture an immutable manifest for a SubjectRun and persist it privately.
     *
     * @param SubjectRun $run
     * @return array{manifest_ref: string, manifest_sha256: string, manifest: array}
     */
    public function capture(SubjectRun $run): array
    {
        $items = $run->items()->orderBy('pdf_ordinal')->get();
        if ($items->isEmpty()) {
            throw new HttpException(422, 'Cannot capture manifest for run with no items.');
        }

        $manifestRef = (string) Str::uuid();

        $itemDescriptors = [];
        foreach ($items as $item) {
            $itemDescriptors[] = [
                'item_id' => $item->id,
                'pdf_ordinal' => (int) $item->pdf_ordinal,
                'display_title' => $item->display_title,
                'original_filename' => $item->original_filename,
                'input_sha256' => $item->input_sha256,
                'input_bytes' => (int) $item->input_bytes,
                'upload_attempt_id' => $item->upload_attempt_id,
                'accepted_input_ref' => $item->accepted_input_ref,
            ];
        }

        $manifestData = [
            'schema_version' => 1,
            'manifest_ref' => $manifestRef,
            'run_id' => $run->id,
            'owner_user_id' => (int) $run->owner_user_id,
            'subject_id' => (int) $run->subject_id,
            'origin' => $run->origin,
            'protocol' => $run->protocol,
            'mode' => $run->mode,
            'qualification_mode' => $run->qualification_mode,
            'settings_version_id' => (int) $run->settings_version_id,
            'capture_sha256' => $run->capture_sha256,
            'framework_version_id' => $run->framework_version_id,
            'submission_key' => $run->submission_key,
            'request_sha256' => $run->request_sha256,
            'total_pdfs' => count($itemDescriptors),
            'items' => $itemDescriptors,
            'captured_at' => now()->toIso8601String(),
        ];

        $encoded = json_encode($manifestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > self::MAX_MANIFEST_BYTES) {
            throw new HttpException(422, 'Captured manifest exceeds 32 KiB metadata limit.');
        }

        $manifestSha256 = hash('sha256', $encoded);

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $manifestRef)) {
            throw new HttpException(422, 'Invalid manifest_ref UUID format.');
        }

        $path = "manifests/{$manifestRef}.json";
        Storage::disk('v3_private')->put($path, $encoded);

        $command = PipelineCommand::where('run_id', $run->id)->where('action', 'submit')->first();
        if ($command) {
            $command->update(['private_payload_ref' => $manifestRef]);
        }

        return [
            'manifest_ref' => $manifestRef,
            'manifest_sha256' => $manifestSha256,
            'manifest' => $manifestData,
        ];
    }

    /**
     * Resolve and verify a captured manifest from private storage.
     *
     * @param string $manifestRef
     * @return array
     */
    public function resolve(string $manifestRef): array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $manifestRef)) {
            throw new HttpException(422, 'Invalid manifest_ref UUID format.');
        }

        $path = "manifests/{$manifestRef}.json";
        if (! Storage::disk('v3_private')->exists($path)) {
            throw new HttpException(404, "Manifest {$manifestRef} not found in private storage.");
        }

        $fullPath = Storage::disk('v3_private')->path($path);
        if (is_link($fullPath)) {
            throw new HttpException(403, "Symlink forbidden for manifest {$manifestRef}.");
        }

        $content = Storage::disk('v3_private')->get($path);
        if (strlen($content) > self::MAX_MANIFEST_BYTES) {
            throw new HttpException(422, 'Manifest exceeds maximum allowed byte limit.');
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new HttpException(422, 'Corrupted manifest JSON.');
        }

        return $data;
    }
}
