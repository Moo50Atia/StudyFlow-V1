<?php

namespace App\Services\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\DevicePairing;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\SubjectRun;
use App\Services\LearningAccess;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceManager
{
    public function __construct(
        protected LearningAccess $learningAccess
    ) {}

    /**
     * Create a masked one-time pairing invitation.
     */
    public function createPairingInvitation(
        User $owner,
        string $deviceName,
        ?int $tenantId = null,
        array $scopes = ['tenant:all'],
        array $capabilities = ['notebooklm_v1', 'static_three_file_v1']
    ): array {
        if (! $this->learningAccess->canManageProcessingDevices($owner)) {
            throw new LocalWorkerProtocolException(
                'Only platform owner can manage processing devices',
                'forbidden',
                403
            );
        }

        // Random >= 256 bits (32 bytes = 64 hex chars)
        $rawSecret = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $rawSecret);
        $expiresAt = now()->addMinutes(10);

        $pairing = DevicePairing::create([
            'id' => (string) Str::uuid(),
            'pairing_code_hash' => $codeHash,
            'platform_owner_id' => $owner->id,
            'device_name' => $deviceName,
            'tenant_id' => $tenantId,
            'scopes' => $scopes,
            'capabilities' => $capabilities,
            'expires_at' => $expiresAt,
        ]);

        return [
            'invitation_id' => $pairing->id,
            'device_name' => $pairing->device_name,
            'pairing_secret' => $rawSecret,
            'pairing_code' => $rawSecret,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Redeem a pairing code for a persistent device credential.
     */
    public function redeemPairing(string $pairingSecret): array
    {
        $codeHash = hash('sha256', trim($pairingSecret));

        return DB::transaction(function () use ($codeHash) {
            $pairing = DevicePairing::where('pairing_code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if (! $pairing) {
                throw new LocalWorkerProtocolException(
                    'Invalid pairing invitation code',
                    'invalid_pairing',
                    401
                );
            }

            if ($pairing->redeemed_at !== null) {
                throw new LocalWorkerProtocolException(
                    'Pairing invitation has already been consumed',
                    'pairing_already_consumed',
                    409
                );
            }

            if ($pairing->expires_at->isPast()) {
                throw new LocalWorkerProtocolException(
                    'Pairing invitation has expired',
                    'pairing_expired',
                    401
                );
            }

            $deviceToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $deviceToken);
            $expiresAt = now()->addDays(30);

            $device = ProcessingDevice::create([
                'id' => (string) Str::uuid(),
                'name' => $pairing->device_name,
                'tenant_id' => $pairing->tenant_id,
                'platform_owner_id' => $pairing->platform_owner_id,
                'token_hash' => $tokenHash,
                'scopes' => $pairing->scopes,
                'capabilities' => $pairing->capabilities,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'last_seen_at' => now(),
            ]);

            $pairing->update([
                'redeemed_at' => now(),
                'redeemed_device_id' => $device->id,
            ]);

            return [
                'schema_version' => 1,
                'device_id' => $device->id,
                'device_token' => $deviceToken,
                'device_name' => $device->name,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    /**
     * Authenticate a device using its Bearer token.
     */
    public function authenticateDevice(string $bearerToken): ProcessingDevice
    {
        $token = trim($bearerToken);
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        if (empty($token)) {
            throw new LocalWorkerProtocolException('Missing device credential', 'unauthenticated', 401);
        }

        $tokenHash = hash('sha256', $token);
        $device = ProcessingDevice::where('token_hash', $tokenHash)->first();

        if (! $device) {
            throw new LocalWorkerProtocolException('Invalid device credential', 'unauthenticated', 401);
        }

        if ($device->status === 'revoked') {
            throw new LocalWorkerProtocolException('Device credential has been revoked', 'device_revoked', 403);
        }

        if ($device->status !== 'active') {
            throw new LocalWorkerProtocolException("Device is in inactive status '{$device->status}'", 'device_inactive', 403);
        }

        if ($device->expires_at->isPast()) {
            throw new LocalWorkerProtocolException('Device credential has expired', 'credential_expired', 401);
        }

        $device->update(['last_seen_at' => now()]);

        return $device;
    }

    /**
     * Revoke a device credential.
     */
    public function revokeDevice(User $owner, string $deviceId, string $reason = 'manual_revocation'): ProcessingDevice
    {
        if (! $this->learningAccess->canManageProcessingDevices($owner)) {
            throw new LocalWorkerProtocolException('Unauthorized', 'forbidden', 403);
        }

        return DB::transaction(function () use ($deviceId, $reason) {
            $device = ProcessingDevice::where('id', $deviceId)->lockForUpdate()->firstOrFail();
            $device->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

            // End each lease and classify its task atomically. An in-flight
            // provider effect needs owner reconciliation; a task with no
            // dispatched/uncertain effect can safely return to the queue.
            $assignments = LocalAssignment::query()->where('device_id', $deviceId)
                ->where('status', 'active')->lockForUpdate()->get();
            foreach ($assignments as $assignment) {
                $task = LocalTask::query()->whereKey($assignment->task_id)->lockForUpdate()->first();
                $assignment->update([
                    'status' => 'expired',
                    'lease_expires_at' => now(),
                ]);
                if (!$task || $task->active_assignment_id !== $assignment->id
                    || in_array($task->status, ['completed', 'cancelled', 'failed'], true)) {
                    continue;
                }

                $hasUncertainEffect = $task->effects()->whereIn('status', ['dispatching', 'unknown'])->exists();
                $task->active_assignment_id = null;
                $task->status = $hasUncertainEffect ? 'reconciliation_required' : 'queued';
                $task->save();

                $job = LocalJob::query()->whereKey($task->job_id)->lockForUpdate()->first();
                if ($job) {
                    $job->status = $hasUncertainEffect ? 'held_reconciliation' : 'queued';
                    $job->save();
                    SubjectRun::query()->whereKey($job->run_id)->update([
                        'state' => $hasUncertainEffect ? 'held_reconciliation' : 'queued',
                        'last_updated_at' => now(),
                    ]);
                }
            }

            return $device;
        });
    }

    /**
     * Rotate a device token.
     */
    public function rotateDeviceToken(User $owner, string $deviceId): array
    {
        if (! $this->learningAccess->canManageProcessingDevices($owner)) {
            throw new LocalWorkerProtocolException('Unauthorized', 'forbidden', 403);
        }

        return DB::transaction(function () use ($deviceId) {
            $device = ProcessingDevice::where('id', $deviceId)->lockForUpdate()->firstOrFail();
            $newToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $newToken);
            $expiresAt = now()->addDays(30);

            $device->update([
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'revoked_at' => null,
                'revocation_reason' => null,
            ]);

            return [
                'device_id' => $device->id,
                'device_token' => $newToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }
}
