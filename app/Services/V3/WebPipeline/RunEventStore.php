<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;

class RunEventStore
{
    public const SAFE_CODES = [
        'ok',
        'authentication_required',
        'account_mismatch',
        'provider_rate_limit',
        'provider_unavailable',
        'source_error',
        'map_unreadable',
        'context_incomplete',
        'delivery_uncertain',
        'history_conflict',
        'reset_uncertain',
        'storage_unavailable',
        'receipt_invalid',
        'artifact_missing',
        'artifact_corrupt',
        'scene_package_invalid',
        'build_failed',
        'configuration_invalid',
        'configuration_drift',
        'operation_conflict',
        'control_conflict',
        'stale_version',
        'queue_full',
        'concurrent_update',
        'cancelled',
    ];

    protected const DISALLOWED_KEYS = [
        'body', 'prompt', 'text', 'code', 'secret', 'token',
        'key', 'password', 'exception', 'traceback', 'trace',
        'payload', 'html', 'markdown', 'script', 'student', 'email',
    ];

    /**
     * Atomically append a sanitized event to the run's monotonic event stream.
     */
    public function recordEvent(string $runId, array $data): PipelineEvent
    {
        $eventId = $data['id'] ?? (string) Str::uuid();
        $sanitized = $this->sanitizeEventData($data);

        $attempts = 0;
        $maxAttempts = 4;
        $backoffs = [50000, 100000, 200000];

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () use ($runId, $eventId, $sanitized) {
                    // Check duplicate event id
                    $existing = PipelineEvent::where('run_id', $runId)->where('id', $eventId)->first();
                    if ($existing) {
                        return $existing;
                    }

                    $maxSeq = PipelineEvent::where('run_id', $runId)->max('sequence') ?? 0;
                    $sequence = $maxSeq + 1;

                    return PipelineEvent::create([
                        'id' => $eventId,
                        'run_id' => $runId,
                        'sequence' => $sequence,
                        'operation_id' => $sanitized['operation_id'] ?? null,
                        'kind' => $sanitized['kind'],
                        'phase' => $sanitized['phase'] ?? null,
                        'outcome' => $sanitized['outcome'] ?? null,
                        'safe_code' => $sanitized['safe_code'] ?? 'ok',
                        'attempt' => (int) ($sanitized['attempt'] ?? 1),
                        'state_version' => (int) ($sanitized['state_version'] ?? 1),
                        'counts_json' => $sanitized['counts_json'] ?? null,
                        'occurred_at' => $sanitized['occurred_at'] ?? now(),
                        'historical' => (bool) ($sanitized['historical'] ?? false),
                        'artifact_ref' => $sanitized['artifact_ref'] ?? null,
                    ]);
                });
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'busy')) {
                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        throw new ConcurrentUpdateException("Database locked during event recording", 409, 'concurrent_update', $e);
                    }
                    usleep($backoffs[$attempts - 1] ?? 100000);
                    continue;
                }
                throw $e;
            }
        }

        throw new ConcurrentUpdateException("Concurrency conflict recording event", 409, 'concurrent_update');
    }

    /**
     * Retrieve ordered events after a given sequence.
     *
     * @return Collection<int, PipelineEvent>
     */
    public function getEvents(string $runId, int $afterSequence = 0, int $limit = 100): Collection
    {
        $limit = max(1, min($limit, 100));

        return PipelineEvent::where('run_id', $runId)
            ->where('sequence', '>', $afterSequence)
            ->orderBy('sequence', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Sanitize event payload to guarantee no private data, secrets, or raw code are retained.
     */
    public function sanitizeEventData(array $data): array
    {
        // Strip sensitive keys
        foreach (self::DISALLOWED_KEYS as $disallowed) {
            unset($data[$disallowed]);
        }

        $kind = preg_replace('/[^a-z0-9_]/', '', strtolower($data['kind'] ?? 'unknown_event')) ?: 'unknown_event';
        $safeCode = $this->sanitizeSafeCode($data['safe_code'] ?? null);
        $counts = $this->sanitizeCounts($data['counts_json'] ?? ($data['counts'] ?? null));

        // Ensure artifact_ref is a clean UUID if present
        $artifactRef = null;
        if (! empty($data['artifact_ref']) && is_string($data['artifact_ref'])) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['artifact_ref'])) {
                $artifactRef = strtolower($data['artifact_ref']);
            }
        }

        return [
            'operation_id' => $data['operation_id'] ?? null,
            'kind' => $kind,
            'phase' => ! empty($data['phase']) ? (string) $data['phase'] : null,
            'outcome' => ! empty($data['outcome']) ? (string) $data['outcome'] : null,
            'safe_code' => $safeCode,
            'attempt' => isset($data['attempt']) ? (int) $data['attempt'] : 1,
            'state_version' => isset($data['state_version']) ? (int) $data['state_version'] : 1,
            'counts_json' => $counts,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'historical' => (bool) ($data['historical'] ?? false),
            'artifact_ref' => $artifactRef,
        ];
    }

    /**
     * Validate or normalize a safe code.
     */
    public function sanitizeSafeCode(?string $code): string
    {
        if (! $code) {
            return 'ok';
        }

        $normalized = strtolower(trim($code));
        if (in_array($normalized, self::SAFE_CODES, true)) {
            return $normalized;
        }

        // Allow ASCII safe codes matching pattern
        if (preg_match('/^[a-z0-9_]{1,50}$/', $normalized)) {
            return $normalized;
        }

        return 'unknown';
    }

    /**
     * Sanitize counts dictionary to only allow bounded non-negative integers.
     */
    public function sanitizeCounts(mixed $counts): ?array
    {
        if (! is_array($counts) || empty($counts)) {
            return null;
        }

        $clean = [];
        foreach ($counts as $k => $v) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
            if (! $key || in_array($key, self::DISALLOWED_KEYS, true)) {
                continue;
            }

            if (is_null($v)) {
                $clean[$key] = null;
            } elseif (is_numeric($v)) {
                $clean[$key] = max(0, (int) $v);
            }
        }

        return empty($clean) ? null : $clean;
    }
}
