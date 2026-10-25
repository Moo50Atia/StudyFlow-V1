<?php

namespace App\Services\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\ProcessingDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Owner-scoped, read-only health probes for remote NotebookLM workers. */
final class ProviderHealthProbeService
{
    private const TTL_SECONDS = 120;

    public function begin(User $owner): array
    {
        $device = ProcessingDevice::query()
            ->where('platform_owner_id', $owner->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) {
                $query->whereJsonContains('capabilities', 'notebooklm_authoring_v1')
                    ->orWhereJsonContains('capabilities', 'notebooklm_v1');
            })
            ->orderByDesc('last_seen_at')
            ->first();

        if (! $device || ! $device->isActive()) {
            return ['state' => 'worker_unavailable', 'message' => 'No active NotebookLM worker is enrolled for this account.'];
        }

        $probeId = (string) Str::uuid();
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);
        $probe = [
            'schema_version' => 1,
            'probe_id' => $probeId,
            'owner_id' => $owner->id,
            'device_id' => $device->id,
            'device_name' => $device->name,
            'state' => 'pending',
            'requested_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        Cache::put($this->probeKey($probeId), $probe, $expiresAt);

        $indexKey = $this->requestIndexKey($device->id);
        $requestIds = array_values(array_filter(Cache::get($indexKey, []), fn ($id) => Cache::has($this->probeKey($id))));
        $requestIds[] = $probeId;
        Cache::put($indexKey, array_slice(array_values(array_unique($requestIds)), -64), $expiresAt);

        return ['state' => 'pending', 'probe_id' => $probeId, 'device_id' => $device->id,
            'device_name' => $device->name, 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function pendingForDevice(ProcessingDevice $device): array
    {
        $indexKey = $this->requestIndexKey($device->id);
        $requestIds = Cache::get($indexKey, []);
        $pending = [];
        foreach (is_array($requestIds) ? $requestIds : [] as $id) {
            $probe = Cache::get($this->probeKey((string) $id));
            if (is_array($probe) && $probe['device_id'] === $device->id && $probe['state'] === 'pending') {
                $pending[] = ['probe_id' => $probe['probe_id'], 'expires_at' => $probe['expires_at']];
            }
        }

        return ['schema_version' => 1, 'probes' => $pending];
    }

    public function report(ProcessingDevice $device, array $payload): array
    {
        $allowedKeys = ['probe_id', 'state', 'safe_code', 'profile_generation'];
        if (array_diff(array_keys($payload), $allowedKeys) !== []) {
            return ['error' => 'invalid_report'];
        }
        $probeId = $payload['probe_id'] ?? null;
        if (! is_string($probeId) || ! Str::isUuid($probeId)) {
            return ['error' => 'invalid_probe'];
        }
        $probe = Cache::get($this->probeKey($probeId));
        if (! is_array($probe) || $probe['device_id'] !== $device->id) {
            return ['error' => 'probe_unavailable'];
        }
        if ($probe['state'] === 'pending' && now()->greaterThanOrEqualTo($probe['expires_at'])) {
            Cache::forget($this->probeKey($probeId));
            return ['error' => 'probe_expired'];
        }

        $state = $payload['state'] ?? null;
        if (! in_array($state, ['ready', 'authentication_required', 'unavailable'], true)
            || ! is_int($payload['profile_generation'] ?? null)
            || $payload['profile_generation'] < 0
            || $payload['profile_generation'] > 2147483647) {
            return ['error' => 'invalid_report'];
        }
        $safeCodes = ['ok', 'authentication_required', 'account_mismatch', 'provider_unavailable', 'profile_unverified'];
        $safeCode = $payload['safe_code'] ?? null;
        if (! is_string($safeCode) || ! in_array($safeCode, $safeCodes, true)) {
            return ['error' => 'invalid_report'];
        }
        if (($state === 'ready') !== ($safeCode === 'ok')
            || ($state === 'authentication_required' && ! in_array($safeCode, ['authentication_required', 'account_mismatch', 'profile_unverified'], true))
            || ($state === 'unavailable' && $safeCode !== 'provider_unavailable')) {
            return ['error' => 'invalid_report'];
        }

        if ($probe['state'] !== 'pending') {
            return $probe['state'] === $state
                && ($probe['safe_code'] ?? null) === $safeCode
                && ($probe['profile_generation'] ?? null) === $payload['profile_generation']
                    ? ['schema_version' => 1, 'probe_id' => $probeId, 'accepted' => true]
                    : ['error' => 'probe_unavailable'];
        }

        $probe['state'] = $state;
        $probe['safe_code'] = $safeCode;
        $probe['profile_generation'] = $payload['profile_generation'];
        $probe['verified_at'] = $state === 'ready' ? now()->toIso8601String() : null;
        $probe['received_at'] = now()->toIso8601String();
        Cache::put($this->probeKey($probeId), $probe, now()->addDay());

        return ['schema_version' => 1, 'probe_id' => $probeId, 'accepted' => true];
    }

    public function status(User $owner, string $probeId): ?array
    {
        if (! Str::isUuid($probeId)) {
            return null;
        }
        $probe = Cache::get($this->probeKey($probeId));
        if (! is_array($probe) || (int) $probe['owner_id'] !== (int) $owner->id) {
            return null;
        }

        return [
            'target' => 'local_worker',
            'state' => $probe['state'],
            'healthy' => $probe['state'] === 'ready',
            'safe_code' => $probe['safe_code'] ?? null,
            'profile_generation' => $probe['profile_generation'] ?? null,
            'checked_at' => $probe['verified_at'] ?? $probe['received_at'] ?? null,
            'device_name' => $probe['device_name'] ?? null,
            'message' => match ($probe['state']) {
                'ready' => 'The local worker verified its NotebookLM account.',
                'authentication_required' => 'The local worker needs a verified NotebookLM session.',
                'unavailable' => 'The local worker could not verify NotebookLM.',
                default => 'Waiting for the local worker to check its NotebookLM account.',
            },
        ];
    }

    private function probeKey(string $probeId): string
    {
        return 'v3-notebooklm-health-probe:'.$probeId;
    }

    private function requestIndexKey(string $deviceId): string
    {
        return 'v3-notebooklm-health-probes:'.$deviceId;
    }
}
