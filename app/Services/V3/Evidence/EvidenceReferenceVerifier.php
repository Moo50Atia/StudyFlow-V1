<?php

namespace App\Services\V3\Evidence;

use Illuminate\Support\Facades\Storage;

class EvidenceReferenceVerifier
{
    /**
     * Verify whether a private artifact exists and matches its expected digest.
     *
     * @return array{content_state: string, gap_code: ?string}
     */
    public function verifyFile(?string $path, ?string $expectedSha256 = null, string $disk = 'v3_private'): array
    {
        if ($path === null || trim($path) === '') {
            return [
                'content_state' => 'not_applicable',
                'gap_code' => null,
            ];
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return [
                'content_state' => 'missing_or_corrupt',
                'gap_code' => 'missing_file',
            ];
        }

        if ($expectedSha256 !== null) {
            $bytes = $storage->get($path);
            $actualSha256 = hash('sha256', $bytes);

            if (strtolower($actualSha256) !== strtolower($expectedSha256)) {
                return [
                    'content_state' => 'missing_or_corrupt',
                    'gap_code' => 'digest_mismatch',
                ];
            }
        }

        return [
            'content_state' => 'available',
            'gap_code' => null,
        ];
    }
}
