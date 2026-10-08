<?php

namespace App\Services\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use Illuminate\Support\Facades\Storage;

class AcceptedPdfReader
{
    /**
     * Open a verified private stream for the given processing case.
     * Checks case -> Document owner match, ready status, file existence, size and sha256 checksum.
     *
     * @return resource Stream resource positioned at beginning of file
     * @throws \RuntimeException
     */
    public function openForCase(ProcessingCase $case)
    {
        $document = Document::where('id', $case->document_id)
            ->where('owner_user_id', $case->owner_user_id)
            ->first();

        if (! $document) {
            throw new \RuntimeException("Associated document not found for case {$case->id}.");
        }

        if ($document->state !== 'ready') {
            throw new \RuntimeException("Document {$document->id} is not in ready state.");
        }

        $disk = Storage::disk('v3_private');
        if (! $disk->exists($document->storage_path)) {
            throw new \RuntimeException("Accepted PDF file is missing from private storage.");
        }

        $stream = $disk->readStream($document->storage_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not read stream for accepted PDF {$document->storage_path}.");
        }

        // Verify SHA-256 and size
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);
        $calculatedHash = hash_final($ctx);

        if ($calculatedHash !== $document->sha256) {
            fclose($stream);
            throw new \RuntimeException("Accepted PDF checksum mismatch in storage.");
        }

        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
            return $stream;
        }

        fclose($stream);
        return $disk->readStream($document->storage_path);
    }
}
