<?php

namespace App\Services\V3\LocalWorker;

use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;

/** Dispatches a fenced worker result to its task-kind acceptance service. */
final class ResultAcceptanceService
{
    public function __construct(
        protected AuthoringResultAcceptor $authoring,
        protected SceneResultAcceptor $scenes,
    ) {}

    public function acceptResult(
        LocalTask $task,
        LocalAssignment $assignment,
        ProcessingDevice $device,
        array $payload,
        ?string $wireDigest = null
    ): array {
        return match ($task->task_kind) {
            'author_prompts' => $this->acceptAuthoringResult($task, $assignment, $device, $payload, $wireDigest),
            'return_scenes' => $this->acceptSceneResult($task, $assignment, $device, $payload, $wireDigest),
            default => throw new LocalWorkerProtocolException("Unsupported task kind '{$task->task_kind}'", 'invalid_task_kind', 422),
        };
    }

    public function acceptAuthoringResult(
        LocalTask $task,
        LocalAssignment $assignment,
        ProcessingDevice $device,
        array $payload,
        ?string $wireDigest = null
    ): array {
        return $this->authoring->acceptAuthoringResult($task, $assignment, $device, $payload, $wireDigest);
    }

    public function acceptSceneResult(
        LocalTask $task,
        LocalAssignment $assignment,
        ProcessingDevice $device,
        array $payload,
        ?string $wireDigest = null
    ): array {
        return $this->scenes->acceptSceneResult($task, $assignment, $device, $payload, $wireDigest);
    }
}
