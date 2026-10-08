<?php

namespace App\Data\V3;

class OperationSummaryData
{
    public const ALLOWED_STAGES = [
        'intake',
        'extraction',
        'sourcing',
        'mapping',
        'sections',
        'prompting',
        'prompt_generation',
        'prompt_qualification',
        'scene_generation',
        'scene_authoring',
        'scene_qualification',
        'teacher_review',
        'complete',
        'unknown',
    ];

    public const ALLOWED_STATUSES = [
        'queued',
        'running',
        'awaiting_review',
        'blocked',
        'failed',
        'cancelled',
        'approved',
        'unknown',
    ];

    public const ALLOWED_ERRORS = [
        'validation',
        'source_unreadable',
        'source_unavailable',
        'prompt_generation_failed',
        'prompt_qualification_failed',
        'scene_generation_failed',
        'scene_qualification_failed',
        'provider_unavailable',
        'provider_rate_limited',
        'policy_violation',
        'policy_denied',
        'internal',
        'settings_unavailable',
        'workflow_start_overdue',
        'workflow_history_missing',
        'workflow_identity_conflict',
        'milestone_rejected',
        'temporal_closed',
        'timeout',
        'unknown',
    ];

    public readonly string $caseId;

    public readonly string $stageCode;

    public readonly string $statusCode;

    public readonly ?string $errorCode;

    public readonly string $createdAt;

    public readonly string $updatedAt;

    public readonly int $attemptCount;

    public readonly int $sceneCount;

    public readonly int $approvedSceneCount;

    public function __construct(
        string $caseId,
        string $stageCode,
        string $statusCode,
        ?string $errorCode,
        string $createdAt,
        string $updatedAt,
        int $attemptCount,
        int $sceneCount,
        int $approvedSceneCount
    ) {
        if ($attemptCount < 0 || $sceneCount < 0 || $approvedSceneCount < 0) {
            throw new \InvalidArgumentException('Counts must be non-negative integers.');
        }

        $this->caseId = $caseId;
        $this->stageCode = in_array($stageCode, self::ALLOWED_STAGES, true) ? $stageCode : 'unknown';
        $this->statusCode = in_array($statusCode, self::ALLOWED_STATUSES, true) ? $statusCode : 'unknown';

        if ($errorCode === null || $errorCode === '') {
            $this->errorCode = null;
        } else {
            $this->errorCode = in_array($errorCode, self::ALLOWED_ERRORS, true) ? $errorCode : 'unknown';
        }

        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->attemptCount = $attemptCount;
        $this->sceneCount = $sceneCount;
        $this->approvedSceneCount = $approvedSceneCount;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            caseId: (string) ($data['case_id'] ?? ''),
            stageCode: (string) ($data['stage_code'] ?? 'unknown'),
            statusCode: (string) ($data['status_code'] ?? 'unknown'),
            errorCode: isset($data['error_code']) ? (string) $data['error_code'] : null,
            createdAt: (string) ($data['created_at'] ?? now()->toIso8601String()),
            updatedAt: (string) ($data['updated_at'] ?? now()->toIso8601String()),
            attemptCount: (int) ($data['attempt_count'] ?? 0),
            sceneCount: (int) ($data['scene_count'] ?? 0),
            approvedSceneCount: (int) ($data['approved_scene_count'] ?? 0)
        );
    }

    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'stage_code' => $this->stageCode,
            'status_code' => $this->statusCode,
            'error_code' => $this->errorCode,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'attempt_count' => $this->attemptCount,
            'scene_count' => $this->sceneCount,
            'approved_scene_count' => $this->approvedSceneCount,
        ];
    }
}
