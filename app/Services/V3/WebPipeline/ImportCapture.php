<?php

namespace App\Services\V3\WebPipeline;

class ImportCapture
{
    public function __construct(
        public readonly string $runId,
        public readonly int $subjectId,
        public readonly int $teacherId,
        public readonly int $actorAdminId,
        public readonly string $importId,
        public readonly array $subjectConfig,
        public readonly array $pdfArchives,
        public readonly array $scenePrompts,
        public readonly array $handoffs,
        public readonly array $filesToCopy,
        public readonly string $digest,
        public readonly bool $isDryRun = false,
        public readonly ?string $partialReason = null,
        public readonly array $exportedLessons = []
    ) {}

    public function isPartial(): bool
    {
        return ! empty($this->partialReason);
    }
}
