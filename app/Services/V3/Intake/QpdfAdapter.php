<?php

namespace App\Services\V3\Intake;

use Symfony\Component\Process\Process;

class QpdfAdapter
{
    protected ?string $binaryPath = null;

    public function __construct(?string $binaryPath = null)
    {
        $this->binaryPath = $binaryPath;
    }

    public function isAvailable(): bool
    {
        $binary = $this->binaryPath ?? 'qpdf';
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
     * Inspect PDF structure, encryption, page count and active content.
     *
     * @return array{valid: bool, reason_code?: string, page_count?: int, error_type?: 'deterministic'|'transient'}
     */
    public function check(string $filePath, int $maxPages): array
    {
        $binary = $this->binaryPath ?? 'qpdf';

        if (! $this->isAvailable()) {
            return [
                'valid' => false,
                'reason_code' => 'inspection_unavailable',
                'error_type' => 'transient',
            ];
        }

        // 1. Check encryption: qpdf --is-encrypted exits 0 if encrypted, 2 if unencrypted
        $encProcess = new Process([$binary, '--is-encrypted', $filePath]);
        $encProcess->setTimeout(30);
        $encProcess->run();

        $encExit = $encProcess->getExitCode();
        if ($encExit === 0) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_encrypted',
                'error_type' => 'deterministic',
            ];
        }

        if ($encExit !== 2) {
            // Exit code other than 2 (unencrypted) or 0 (encrypted) indicates corrupted or unparseable PDF
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        // 2. Structural integrity check: qpdf --check exits 0 on clean, 3 on warnings
        $checkProcess = new Process([$binary, '--check', $filePath]);
        $checkProcess->setTimeout(60);
        $checkProcess->run();

        if ($checkProcess->getExitCode() !== 0) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        // 3. Page count check
        $pagesProcess = new Process([$binary, '--show-npages', $filePath]);
        $pagesProcess->setTimeout(30);
        $pagesProcess->run();

        if (! $pagesProcess->isSuccessful()) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        $pageCount = (int) trim($pagesProcess->getOutput());
        if ($pageCount <= 0) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        if ($pageCount > $maxPages) {
            return [
                'valid' => false,
                'reason_code' => 'page_limit',
                'error_type' => 'deterministic',
            ];
        }

        // 4. JSON v2 Active Content Inspection
        // qpdf --json=2 --json-stream-data=none --json-key=qpdf {filePath}
        // Max buffer 32 MiB, timeout 120s
        $jsonProcess = new Process([
            $binary,
            '--json=2',
            '--json-stream-data=none',
            '--json-key=qpdf',
            $filePath,
        ]);
        $jsonProcess->setTimeout(120);

        try {
            $jsonProcess->run();
        } catch (\Throwable) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        if (! $jsonProcess->isSuccessful()) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        $rawOutput = $jsonProcess->getOutput();
        if (strlen($rawOutput) > 32 * 1024 * 1024) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        $data = json_decode($rawOutput, true);
        if (! is_array($data) || ! isset($data['qpdf']) || ! is_array($data['qpdf'])) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        $qpdfArray = $data['qpdf'];
        if (! isset($qpdfArray[0]['jsonversion']) || (int) $qpdfArray[0]['jsonversion'] !== 2) {
            return [
                'valid' => false,
                'reason_code' => 'inspection_unavailable',
                'error_type' => 'transient',
            ];
        }

        if (! isset($qpdfArray[1]) || ! is_array($qpdfArray[1])) {
            return [
                'valid' => false,
                'reason_code' => 'pdf_malformed',
                'error_type' => 'deterministic',
            ];
        }

        // Check for forbidden active content or embedded files
        if ($this->hasForbiddenActiveContent($qpdfArray[1])) {
            return [
                'valid' => false,
                'reason_code' => 'active_content',
                'error_type' => 'deterministic',
            ];
        }

        return [
            'valid' => true,
            'page_count' => $pageCount,
        ];
    }

    /**
     * Recursively inspect parsed JSON objects for forbidden active content.
     */
    protected function hasForbiddenActiveContent(array $objects): bool
    {
        $forbiddenKeys = [
            '/openaction',
            '/aa',
            '/js',
            '/javascript',
            '/launch',
            '/embeddedfiles',
            '/ef',
            '/filespec',
            '/acroform',
            '/xfa',
            '/richmedia',
        ];

        $forbiddenActionTypes = [
            '/javascript',
            '/launch',
            '/submitform',
            '/importdata',
            '/gotor',
        ];

        $stack = [$objects];

        while (! empty($stack)) {
            $current = array_pop($stack);

            if (! is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                $lowerKey = is_string($key) ? strtolower($key) : '';

                if (in_array($lowerKey, $forbiddenKeys, true)) {
                    return true;
                }

                // Check action type /S
                if ($lowerKey === '/s' && is_string($value)) {
                    if (in_array(strtolower($value), $forbiddenActionTypes, true)) {
                        return true;
                    }
                }

                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return false;
    }
}
