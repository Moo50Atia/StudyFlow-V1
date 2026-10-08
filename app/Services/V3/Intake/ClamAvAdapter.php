<?php

namespace App\Services\V3\Intake;

use Symfony\Component\Process\Process;

class ClamAvAdapter
{
    protected ?string $binaryPath = null;

    public function __construct(?string $binaryPath = null)
    {
        $this->binaryPath = $binaryPath;
    }

    public function isAvailable(): bool
    {
        $binary = $this->binaryPath ?? 'clamdscan';
        $process = new Process([$binary, '--version']);
        $process->setTimeout(5);

        try {
            $process->run();
            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Scan file for malware using clamdscan.
     *
     * @return array{valid: bool, reason_code?: string, error_type?: 'deterministic'|'transient'}
     */
    public function scan(string $filePath): array
    {
        $binary = $this->binaryPath ?? 'clamdscan';

        if (! $this->isAvailable()) {
            return [
                'valid' => false,
                'reason_code' => 'inspection_unavailable',
                'error_type' => 'transient',
            ];
        }

        // clamdscan --fdpass --no-summary {filePath}
        $process = new Process([
            $binary,
            '--fdpass',
            '--no-summary',
            $filePath,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable) {
            return [
                'valid' => false,
                'reason_code' => 'inspection_unavailable',
                'error_type' => 'transient',
            ];
        }

        $exitCode = $process->getExitCode();

        // Exit 0: Clean
        if ($exitCode === 0) {
            return [
                'valid' => true,
            ];
        }

        // Exit 1: Infected
        if ($exitCode === 1) {
            return [
                'valid' => false,
                'reason_code' => 'malware_detected',
                'error_type' => 'deterministic',
            ];
        }

        // Exit 2 or other: Daemon error, socket error, or timeout
        return [
            'valid' => false,
            'reason_code' => 'inspection_unavailable',
            'error_type' => 'transient',
        ];
    }
}
