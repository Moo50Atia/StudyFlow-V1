<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineCommand;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;

class CommandLedger
{
    /**
     * Compute canonical SHA-256 digest from payload.
     */
    public function computeDigest(mixed $payload): string
    {
        if (is_array($payload)) {
            $normalized = $this->normalizeArray($payload);
            $serialized = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($payload)) {
            $serialized = $payload;
        } elseif (is_null($payload)) {
            $serialized = '';
        } else {
            $serialized = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return hash('sha256', $serialized);
    }

    /**
     * Recursively sort array keys to guarantee canonical JSON serialization.
     */
    protected function normalizeArray(array $data): array
    {
        $normalized = [];
        $keys = array_keys($data);
        sort($keys);

        foreach ($keys as $key) {
            $value = $data[$key];
            if (is_array($value)) {
                $normalized[$key] = $this->normalizeArray($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Atomically reserve or deduplicate a pipeline command under version fencing.
     *
     * @return array{command: PipelineCommand, status: string, state_version: int}
     *
     * @throws CommandConflictException
     * @throws StaleVersionException
     * @throws ConcurrentUpdateException
     */
    public function recordCommand(
        string $runId,
        int $actorUserId,
        string $action,
        int $expectedVersion,
        mixed $payload = null,
        ?string $commandId = null,
        ?string $requestSha = null,
        ?string $privatePayloadRef = null
    ): array {
        $commandId = $commandId ?: (string) Str::uuid();
        $requestSha = $requestSha ?: $this->computeDigest($payload ?? ['action' => $action, 'expected_version' => $expectedVersion]);

        $attempts = 0;
        $maxAttempts = 4;
        $backoffs = [50000, 100000, 200000]; // microseconds

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () use ($runId, $actorUserId, $action, $expectedVersion, $commandId, $requestSha, $privatePayloadRef) {
                    // Check if command already exists
                    $existing = PipelineCommand::where('id', $commandId)->first();
                    if ($existing) {
                        if ($existing->request_sha256 === $requestSha) {
                            return [
                                'command' => $existing,
                                'status' => 'duplicate',
                                'state_version' => (int) $existing->expected_version,
                            ];
                        }

                        throw new CommandConflictException(
                            "Command {$commandId} already exists with different request digest",
                            409,
                            'control_conflict'
                        );
                    }

                    // Lock and verify run state_version
                    $run = SubjectRun::lockForUpdate()->find($runId);
                    if (! $run) {
                        throw new \RuntimeException("Subject run {$runId} not found");
                    }

                    if ((int) $run->state_version !== (int) $expectedVersion) {
                        throw new StaleVersionException(
                            "State version conflict: expected {$expectedVersion}, actual {$run->state_version}",
                            409,
                            'stale_version'
                        );
                    }

                    $command = PipelineCommand::create([
                        'id' => $commandId,
                        'run_id' => $runId,
                        'actor_user_id' => $actorUserId,
                        'action' => $action,
                        'request_sha256' => $requestSha,
                        'expected_version' => $expectedVersion,
                        'private_payload_ref' => $privatePayloadRef,
                        'state' => 'accepted',
                        'accepted_at' => now(),
                    ]);

                    return [
                        'command' => $command,
                        'status' => 'created',
                        'state_version' => (int) $run->state_version,
                    ];
                });
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'busy')) {
                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        throw new ConcurrentUpdateException("Database locked after multiple retries", 409, 'concurrent_update', $e);
                    }
                    usleep($backoffs[$attempts - 1] ?? 100000);
                    continue;
                }
                throw $e;
            }
        }

        throw new ConcurrentUpdateException("Concurrency conflict", 409, 'concurrent_update');
    }

    /**
     * Mark command as applied and increment state version of run.
     */
    public function applyCommand(
        PipelineCommand|string $command,
        ?string $safeCode = 'ok',
        ?string $resultRef = null
    ): PipelineCommand {
        $commandModel = is_string($command) ? PipelineCommand::findOrFail($command) : $command;

        return DB::transaction(function () use ($commandModel, $safeCode, $resultRef) {
            $run = SubjectRun::lockForUpdate()->find($commandModel->run_id);
            if ($run) {
                $run->increment('state_version');
            }

            $commandModel->update([
                'state' => 'applied',
                'applied_at' => now(),
                'safe_code' => $safeCode,
                'result_ref' => $resultRef,
            ]);

            return $commandModel->fresh();
        });
    }

    /**
     * Mark command as rejected.
     */
    public function rejectCommand(PipelineCommand|string $command, string $safeCode): PipelineCommand
    {
        $commandModel = is_string($command) ? PipelineCommand::findOrFail($command) : $command;

        $commandModel->update([
            'state' => 'rejected',
            'safe_code' => $safeCode,
        ]);

        return $commandModel->fresh();
    }
}
