<?php

namespace App\Services\V3\Intake;

class PdfInspector
{
    public function __construct(
        protected QpdfAdapter $qpdf,
        protected ClamAvAdapter $clamAv
    ) {}

    /**
     * Inspect PDF against structure and malware gates.
     *
     * @return array{valid: bool, reason_code?: string, page_count?: int, error_type?: 'deterministic'|'transient'}
     */
    public function inspect(string $filePath, int $maxPages): array
    {
        // 1. Structure gate (qpdf)
        $qpdfResult = $this->qpdf->check($filePath, $maxPages);
        if (! $qpdfResult['valid']) {
            return $qpdfResult;
        }

        // 2. Malware gate (ClamAV)
        $clamResult = $this->clamAv->scan($filePath);
        if (! $clamResult['valid']) {
            return $clamResult;
        }

        return [
            'valid' => true,
            'page_count' => $qpdfResult['page_count'],
        ];
    }
}
