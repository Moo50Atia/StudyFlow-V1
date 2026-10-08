<?php

namespace App\Services\V3\Access;

use App\Models\User;
use App\Models\V3\V3AccountAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChangeAccountAccess
{
    public function __construct(
        protected AccessAuditRecorder $recorder,
        protected V3AccessResolver $resolver
    ) {}

    /**
     * Update account role and V3 access state via V3 administration endpoint.
     */
    public function updateAccess(
        User $actor,
        int $targetId,
        string $role,
        bool $isActive,
        int $expectedVersion,
        string $expectedRole,
        string $reasonCode
    ): array {
        return DB::transaction(function () use (
            $actor,
            $targetId,
            $role,
            $isActive,
            $expectedVersion,
            $expectedRole,
            $reasonCode
        ) {
            // 1. First-write singleton lock to serialize concurrent mutations
            DB::table('v3_access_control_locks')->where('id', 1)->increment('revision');

            // 2. Reload actor fresh and enforce active admin
            $freshActor = User::find($actor->id);
            if (! $freshActor || $freshActor->role !== 'admin') {
                abort(403, 'V3 access is unavailable.');
            }
            $actorAccess = V3AccountAccess::where('user_id', $freshActor->id)->first();
            if (! $actorAccess || ! $actorAccess->is_active) {
                abort(403, 'V3 access is unavailable.');
            }

            // 3. Reload target
            $target = User::find($targetId);
            if (! $target) {
                abort(404, 'The requested account is unavailable.');
            }

            // 4. Validate expected_role
            if ($target->role !== $expectedRole) {
                abort(409, 'Role conflict: current role does not match expected role.');
            }

            // 5. Validate expected_version
            $access = V3AccountAccess::where('user_id', $target->id)->first();
            $currentVersion = $access ? (int) $access->version : 0;
            if ($currentVersion !== $expectedVersion) {
                abort(409, 'Version conflict: current version does not match expected version.');
            }

            // 6. Role & active validity
            if (! in_array($role, ['admin', 'teacher', 'student'], true)) {
                throw ValidationException::withMessages(['role' => 'Invalid role.']);
            }
            if ($role === 'student' && $isActive) {
                throw ValidationException::withMessages(['is_active' => 'A student cannot have active V3 access.']);
            }

            // 7. Self guards
            if ($freshActor->id === $target->id) {
                if ($role !== 'admin') {
                    abort(409, 'Self-demotion is prohibited.');
                }
                if (! $isActive) {
                    abort(409, 'Self-disablement is prohibited.');
                }
            }

            // 8. Final active admin guard
            $isTargetCurrentlyActiveAdmin = ($target->role === 'admin' && $access && $access->is_active);
            $willTargetRemainActiveAdmin = ($role === 'admin' && $isActive);

            if ($isTargetCurrentlyActiveAdmin && ! $willTargetRemainActiveAdmin) {
                $activeAdminCount = DB::table('users')
                    ->join('v3_account_access', 'users.id', '=', 'v3_account_access.user_id')
                    ->where('users.role', 'admin')
                    ->where('v3_account_access.is_active', 1)
                    ->count();

                if ($activeAdminCount <= 1) {
                    abort(409, 'Cannot remove or suspend the last active administrator.');
                }
            }

            // 9. Semantic reason code & no-op evaluation
            if (! in_array($reasonCode, AccessAuditRecorder::ALLOWED_ACCOUNT_REASONS, true)) {
                throw ValidationException::withMessages(['reason_code' => 'Invalid reason code.']);
            }

            $roleChanged = ($target->role !== $role);
            $currentActive = $access ? (bool) $access->is_active : false;
            $activeChanged = ($currentActive !== $isActive);
            $isNoOp = (! $roleChanged && ! $activeChanged && $access !== null);

            if (! $isNoOp) {
                $this->validateReasonCodeSemantics($reasonCode, $access === null, $roleChanged, $activeChanged, $isActive);
            }

            if ($isNoOp) {
                return [
                    'id' => $target->id,
                    'role' => $target->role,
                    'is_active' => (bool) $access->is_active,
                    'version' => (int) $access->version,
                    'changed' => false,
                ];
            }

            // 10. Mutate state
            $beforeState = [
                'role' => $target->role,
                'is_active' => $currentActive,
                'version' => $currentVersion,
            ];

            if ($roleChanged) {
                $target->role = $role;
                $target->save();
            }

            if ($access === null) {
                $newVersion = 1;
                $access = V3AccountAccess::create([
                    'user_id' => $target->id,
                    'is_active' => $isActive,
                    'version' => $newVersion,
                    'provisioned_at' => now(),
                    'disabled_at' => $isActive ? null : now(),
                ]);
            } else {
                $newVersion = $access->version + 1;
                $access->is_active = $isActive;
                $access->version = $newVersion;
                if (! $isActive && $access->disabled_at === null) {
                    $access->disabled_at = now();
                } elseif ($isActive) {
                    $access->disabled_at = null;
                }
                $access->save();
            }

            $afterState = [
                'role' => $role,
                'is_active' => $isActive,
                'version' => $newVersion,
            ];

            // 11. Atomic audit record
            $requestId = request()->attributes->get('v3_request_id') ?: (string) Str::uuid();
            $this->recorder->recordAccountChanged(
                requestId: $requestId,
                actorUserId: $freshActor->id,
                targetUserId: $target->id,
                reasonCode: $reasonCode,
                beforeState: $beforeState,
                afterState: $afterState,
                routeName: request()->route()?->getName()
            );

            return [
                'id' => $target->id,
                'role' => $role,
                'is_active' => $isActive,
                'version' => $newVersion,
                'changed' => true,
            ];
        }, 3);
    }

    /**
     * Create account via legacy admin store route.
     */
    public function createFromLegacy(User $actor, array $identityData): User
    {
        return DB::transaction(function () use ($actor, $identityData) {
            DB::table('v3_access_control_locks')->where('id', 1)->increment('revision');

            $freshActor = User::find($actor->id);
            if (! $freshActor || $freshActor->role !== 'admin') {
                abort(403, 'V3 access is unavailable.');
            }
            $actorAccess = V3AccountAccess::where('user_id', $freshActor->id)->first();
            if (! $actorAccess || ! $actorAccess->is_active) {
                abort(403, 'V3 access is unavailable.');
            }

            $role = $identityData['role'] ?? 'student';
            if (! in_array($role, ['admin', 'teacher', 'student'], true)) {
                throw ValidationException::withMessages(['role' => 'Invalid role.']);
            }

            $user = User::create([
                'name' => $identityData['name'],
                'email' => $identityData['email'],
                'password' => Hash::make($identityData['password']),
                'role' => $role,
            ]);

            // If created as teacher or admin, provision V3 access
            if (in_array($role, ['teacher', 'admin'], true)) {
                V3AccountAccess::create([
                    'user_id' => $user->id,
                    'is_active' => true,
                    'version' => 1,
                    'provisioned_at' => now(),
                    'disabled_at' => null,
                ]);

                $requestId = (string) Str::uuid();
                $this->recorder->recordAccountChanged(
                    requestId: $requestId,
                    actorUserId: $freshActor->id,
                    targetUserId: $user->id,
                    reasonCode: 'grant_access',
                    beforeState: null,
                    afterState: ['role' => $role, 'is_active' => true, 'version' => 1],
                    routeName: request()->route()?->getName()
                );
            }

            return $user;
        }, 3);
    }

    /**
     * Update account via legacy admin update route.
     */
    public function updateFromLegacy(
        User $actor,
        int $targetId,
        array $identityData,
        int $expectedVersion,
        string $expectedRole
    ): array {
        $freshActor = User::find($actor->id);
        if (! $freshActor || $freshActor->role !== 'admin') {
            abort(403, 'V3 access is unavailable.');
        }
        $actorAccess = V3AccountAccess::where('user_id', $freshActor->id)->first();
        if (! $actorAccess || ! $actorAccess->is_active) {
            abort(403, 'V3 access is unavailable.');
        }

        $target = User::find($targetId);
        if (! $target) {
            abort(404, 'The requested user is unavailable.');
        }

        $access = V3AccountAccess::where('user_id', $target->id)->first();

        // FR-017 / D8: Administrative email and password replacements are prohibited for enrolled V3 accounts
        if ($access !== null) {
            if (isset($identityData['email']) && $identityData['email'] !== $target->email) {
                $this->recorder->recordAccessDenied(
                    requestId: (string) Str::uuid(),
                    actorUserId: $freshActor->id,
                    reasonCode: 'credential_change_denied',
                    routeName: request()->route()?->getName(),
                    targetUserId: $target->id
                );
                abort(403, 'Administrative email changes are prohibited for enrolled V3 accounts.');
            }
            if (! empty($identityData['password'])) {
                $this->recorder->recordAccessDenied(
                    requestId: (string) Str::uuid(),
                    actorUserId: $freshActor->id,
                    reasonCode: 'credential_change_denied',
                    routeName: request()->route()?->getName(),
                    targetUserId: $target->id
                );
                abort(403, 'Administrative password replacement is prohibited for enrolled V3 accounts.');
            }
        }

        return DB::transaction(function () use ($freshActor, $target, $access, $identityData, $expectedVersion, $expectedRole) {
            DB::table('v3_access_control_locks')->where('id', 1)->increment('revision');

            // Expected role check
            if ($target->role !== $expectedRole) {
                abort(409, 'Expected role conflict.');
            }

            // Expected version check
            $currentVersion = $access ? (int) $access->version : 0;
            if ($currentVersion !== $expectedVersion) {
                abort(409, 'Expected version conflict.');
            }

            $desiredRole = $identityData['role'] ?? $target->role;
            if (! in_array($desiredRole, ['admin', 'teacher', 'student'], true)) {
                throw ValidationException::withMessages(['role' => 'Invalid role.']);
            }

            // Name update
            if (isset($identityData['name'])) {
                $target->name = $identityData['name'];
            }

            // Email & password update (only for unenrolled accounts, since enrolled accounts are blocked above)
            if ($access === null) {
                if (isset($identityData['email'])) {
                    $target->email = $identityData['email'];
                }
                if (! empty($identityData['password'])) {
                    $target->password = Hash::make($identityData['password']);
                }
            }

            // Check if role changed
            $roleChanged = ($target->role !== $desiredRole);

            if (! $roleChanged) {
                $target->save();

                return [
                    'id' => $target->id,
                    'role' => $target->role,
                    'is_active' => $access ? (bool) $access->is_active : false,
                    'version' => $currentVersion,
                    'changed' => false,
                ];
            }

            // Role is changing
            // Self-demotion check
            if ($freshActor->id === $target->id && $desiredRole !== 'admin') {
                abort(409, 'Self-demotion is prohibited.');
            }

            // Final active admin check
            if ($target->role === 'admin' && $access && $access->is_active && $desiredRole !== 'admin') {
                $activeAdminCount = DB::table('users')
                    ->join('v3_account_access', 'users.id', '=', 'v3_account_access.user_id')
                    ->where('users.role', 'admin')
                    ->where('v3_account_access.is_active', 1)
                    ->count();

                if ($activeAdminCount <= 1) {
                    abort(409, 'Cannot remove the last active administrator.');
                }
            }

            $beforeState = [
                'role' => $target->role,
                'is_active' => $access ? (bool) $access->is_active : false,
                'version' => $currentVersion,
            ];

            $target->role = $desiredRole;
            $target->save();

            // Handle V3 access row state:
            // If enrolled and changing to student: set is_active = false
            // If changing from student to teacher/admin: keep is_active = false (no implicit restore)
            if ($access !== null) {
                $newVersion = $access->version + 1;
                $access->version = $newVersion;
                if ($desiredRole === 'student') {
                    $access->is_active = false;
                    $access->disabled_at = now();
                }
                $access->save();

                $afterState = [
                    'role' => $desiredRole,
                    'is_active' => (bool) $access->is_active,
                    'version' => $newVersion,
                ];

                $requestId = (string) Str::uuid();
                $this->recorder->recordAccountChanged(
                    requestId: $requestId,
                    actorUserId: $freshActor->id,
                    targetUserId: $target->id,
                    reasonCode: 'legacy_role_change',
                    beforeState: $beforeState,
                    afterState: $afterState,
                    routeName: request()->route()?->getName()
                );

                return [
                    'id' => $target->id,
                    'role' => $desiredRole,
                    'is_active' => (bool) $access->is_active,
                    'version' => $newVersion,
                    'changed' => true,
                ];
            }

            // Never enrolled student: changing role to teacher/admin does NOT auto-enroll via legacy
            return [
                'id' => $target->id,
                'role' => $desiredRole,
                'is_active' => false,
                'version' => 0,
                'changed' => true,
            ];
        }, 3);
    }

    protected function validateReasonCodeSemantics(
        string $reasonCode,
        bool $isNewGrant,
        bool $roleChanged,
        bool $activeChanged,
        bool $isActive
    ): void {
        if ($isNewGrant) {
            if ($reasonCode !== 'grant_access') {
                throw ValidationException::withMessages(['reason_code' => 'Initial access grant requires reason_code grant_access.']);
            }

            return;
        }

        if ($roleChanged && $reasonCode !== 'change_role') {
            throw ValidationException::withMessages(['reason_code' => 'Role change requires reason_code change_role.']);
        }

        if (! $roleChanged && $activeChanged) {
            if (! $isActive && $reasonCode !== 'suspend_access') {
                throw ValidationException::withMessages(['reason_code' => 'Suspension requires reason_code suspend_access.']);
            }
            if ($isActive && $reasonCode !== 'restore_access') {
                throw ValidationException::withMessages(['reason_code' => 'Restoration requires reason_code restore_access.']);
            }
        }
    }
}
