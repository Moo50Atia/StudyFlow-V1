<?php

namespace App\Services\V3\Access;

use App\Models\V3\V3AccessEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AccessAuditRecorder
{
    public const ALLOWED_ACCOUNT_REASONS = [
        'grant_access',
        'suspend_access',
        'restore_access',
        'change_role',
        'bootstrap',
        'legacy_role_change',
    ];

    public const ALLOWED_DENIAL_REASONS = [
        'unauthenticated',
        'not_enrolled',
        'disabled',
        'wrong_role',
        'not_owner',
        'parent_mismatch',
        'access_conflict',
        'retained_account',
        'credential_change_denied',
        'access_unavailable',
        'access_denied',
    ];

    public const ALLOWED_STATE_KEYS = ['role', 'is_active', 'version'];

    public function recordAccountChanged(
        string $requestId,
        ?int $actorUserId,
        int $targetUserId,
        string $reasonCode,
        ?array $beforeState,
        ?array $afterState,
        ?string $routeName = null
    ): V3AccessEvent {
        if (! in_array($reasonCode, self::ALLOWED_ACCOUNT_REASONS, true)) {
            throw new \InvalidArgumentException("Invalid account audit reason code: {$reasonCode}");
        }

        $this->validateStateSnapshot($beforeState);
        $this->validateStateSnapshot($afterState);

        return V3AccessEvent::create([
            'id' => (string) Str::uuid(),
            'request_id' => $requestId,
            'event_type' => 'account_changed',
            'actor_user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'route_name' => $routeName,
            'reason_code' => $reasonCode,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'occurred_at' => now(),
        ]);
    }

    public function recordAccessDenied(
        string $requestId,
        ?int $actorUserId,
        string $reasonCode,
        ?string $routeName = null,
        ?int $targetUserId = null
    ): ?V3AccessEvent {
        if (! in_array($reasonCode, self::ALLOWED_DENIAL_REASONS, true)) {
            throw new \InvalidArgumentException("Invalid denial audit reason code: {$reasonCode}");
        }

        try {
            $existing = DB::table('v3_access_events')
                ->where('request_id', $requestId)
                ->where('event_type', 'access_denied')
                ->first();

            if ($existing) {
                return V3AccessEvent::find($existing->id);
            }

            return V3AccessEvent::create([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'event_type' => 'access_denied',
                'actor_user_id' => $actorUserId,
                'target_user_id' => $targetUserId,
                'route_name' => $routeName,
                'reason_code' => $reasonCode,
                'before_state' => null,
                'after_state' => null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::emergency("V3 access denial audit failed for request: {$requestId}. Error: ".$e->getMessage());

            return null;
        }
    }

    protected function validateStateSnapshot(?array $state): void
    {
        if ($state === null) {
            return;
        }

        foreach (array_keys($state) as $key) {
            if (! in_array($key, self::ALLOWED_STATE_KEYS, true)) {
                throw new \InvalidArgumentException("Disallowed key in state snapshot: {$key}");
            }
        }
    }
}
