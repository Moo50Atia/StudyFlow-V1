<?php

namespace App\Services\V3\Prompts;

use Illuminate\Support\Facades\Storage;

class PrivatePromptArtifactReader
{
    protected string $diskName = 'v3_private';

    /**
     * Read private prompt artifact and verify SHA-256 digest.
     *
     * @throws \RuntimeException if missing or digest mismatch
     */
    public function read(string $artifactPath, string $expectedSha256): string
    {
        $disk = Storage::disk($this->diskName);

        if (! $disk->exists($artifactPath)) {
            PromptWarningLogger::warnOnce('prompt_artifact_missing', [
                'code' => 'prompt_artifact_missing',
                'artifact_path' => $artifactPath,
            ]);
            throw new \RuntimeException("Prompt artifact missing: {$artifactPath}");
        }

        $content = $disk->get($artifactPath);
        $actualSha = hash('sha256', $content);

        if ($actualSha !== $expectedSha256) {
            PromptWarningLogger::warnOnce('prompt_artifact_missing', [
                'code' => 'prompt_artifact_missing',
                'artifact_path' => $artifactPath,
            ]);
            throw new \RuntimeException("Prompt artifact checksum mismatch for {$artifactPath}");
        }

        return $content;
    }
}
