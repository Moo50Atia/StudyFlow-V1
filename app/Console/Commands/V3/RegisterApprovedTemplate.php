<?php

namespace App\Console\Commands\V3;

use App\Models\V3\ApprovedTemplate;
use Illuminate\Console\Command;

class RegisterApprovedTemplate extends Command
{
    protected $signature = 'v3:register-approved-template {kind} {id} {path} {sha256}';

    protected $description = 'Register an owner-approved template into the immutable catalog';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        $id = (string) $this->argument('id');
        $path = (string) $this->argument('path');
        $expectedSha256 = strtolower((string) $this->argument('sha256'));

        if (! in_array($kind, ['feedback', 'scene'], true)) {
            $this->error("Invalid template kind '{$kind}'. Must be 'feedback' or 'scene'.");

            return self::FAILURE;
        }

        if (! preg_match('/^[a-z][a-z0-9_-]{2,63}$/', $id)) {
            $this->error("Invalid template id '{$id}'. Must match ^[a-z][a-z0-9_-]{2,63}$.");

            return self::FAILURE;
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            $this->error('Invalid SHA-256 digest format. Must be 64 lowercase hex characters.');

            return self::FAILURE;
        }

        if (! file_exists($path) || ! is_readable($path)) {
            $this->error("File at path '{$path}' does not exist or is unreadable.");

            return self::FAILURE;
        }

        $size = filesize($path);
        if ($size < 1 || $size > 65536) {
            $this->error("File size {$size} bytes is outside allowed range (1–65536 bytes).");

            return self::FAILURE;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || ! mb_check_encoding($bytes, 'UTF-8')) {
            $this->error('File content is not valid UTF-8 text.');

            return self::FAILURE;
        }

        $actualSha256 = hash('sha256', $bytes);
        if ($actualSha256 !== $expectedSha256) {
            $this->error("Checksum mismatch: expected {$expectedSha256}, got {$actualSha256}.");

            return self::FAILURE;
        }

        $existing = ApprovedTemplate::find($id);
        if ($existing) {
            if ($existing->kind === $kind && $existing->checksum === $expectedSha256 && $existing->body === $bytes) {
                $this->info("id: {$id}, kind: {$kind}, checksum: {$expectedSha256}");

                return self::SUCCESS;
            }

            $this->error("Conflicting template already registered for id '{$id}'.");

            return self::FAILURE;
        }

        ApprovedTemplate::create([
            'id' => $id,
            'kind' => $kind,
            'body' => $bytes,
            'checksum' => $expectedSha256,
            'registered_at' => now(),
        ]);

        $this->info("id: {$id}, kind: {$kind}, checksum: {$expectedSha256}");

        return self::SUCCESS;
    }
}
