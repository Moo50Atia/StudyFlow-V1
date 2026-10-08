<?php

namespace App\Services\V3\Intake;

use Illuminate\Support\Facades\Storage;

class PrivatePdfStore
{
    protected string $disk = 'v3_private';

    public function disk(): string
    {
        return $this->disk;
    }

    public function quarantinePath(string $attemptId): string
    {
        return "quarantine/{$attemptId}.pdf";
    }

    public function acceptedPath(string $documentId): string
    {
        return "accepted/{$documentId}.pdf";
    }

    public function fullPath(string $relativePath): string
    {
        return Storage::disk($this->disk)->path($relativePath);
    }

    public function exists(string $relativePath): bool
    {
        return Storage::disk($this->disk)->exists($relativePath);
    }

    public function size(string $relativePath): int
    {
        return Storage::disk($this->disk)->size($relativePath);
    }

    public function sha256(string $relativePath): string
    {
        $fullPath = $this->fullPath($relativePath);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File does not exist at {$fullPath}");
        }

        return hash_file('sha256', $fullPath);
    }

    public function storeQuarantine(string $attemptId, string $content): int
    {
        $path = $this->quarantinePath($attemptId);
        Storage::disk($this->disk)->put($path, $content);

        return $this->size($path);
    }

    public function delete(string $relativePath): bool
    {
        if ($this->exists($relativePath)) {
            return Storage::disk($this->disk)->delete($relativePath);
        }

        return true;
    }

    public function deleteQuarantine(string $attemptId): bool
    {
        return $this->delete($this->quarantinePath($attemptId));
    }

    public function deleteAccepted(string $documentId): bool
    {
        return $this->delete($this->acceptedPath($documentId));
    }

    public function promoteQuarantineToAccepted(string $attemptId, string $documentId): bool
    {
        $srcRelative = $this->quarantinePath($attemptId);
        $dstRelative = $this->acceptedPath($documentId);

        $srcFull = $this->fullPath($srcRelative);
        $dstFull = $this->fullPath($dstRelative);

        if (! file_exists($srcFull)) {
            throw new \RuntimeException("Source quarantine file does not exist at {$srcFull}");
        }

        $dstDir = dirname($dstFull);
        if (! is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        // Atomic same-disk rename
        if (! rename($srcFull, $dstFull)) {
            throw new \RuntimeException("Failed to rename {$srcFull} to {$dstFull}");
        }

        return true;
    }

    /**
     * @return resource
     */
    public function readStream(string $relativePath)
    {
        $fullPath = $this->fullPath($relativePath);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File does not exist at {$fullPath}");
        }

        $stream = fopen($fullPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open read stream for {$fullPath}");
        }

        return $stream;
    }
}
