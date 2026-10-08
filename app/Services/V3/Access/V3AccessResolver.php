<?php

namespace App\Services\V3\Access;

use App\Models\User;
use App\Models\V3\V3AccountAccess;

class V3AccessResolver
{
    /**
     * Resolve effective V3 access for a user.
     *
     * @return array{
     *     allowed: bool,
     *     reason: string,
     *     user: ?User,
     *     role: ?string,
     *     access: ?V3AccountAccess
     * }
     */
    public function resolve(?User $user): array
    {
        if (! $user) {
            return [
                'allowed' => false,
                'reason' => 'unauthenticated',
                'user' => null,
                'role' => null,
                'access' => null,
            ];
        }

        // Always query fresh from database to prevent trusting cached session state
        $freshUser = User::find($user->id);
        if (! $freshUser) {
            return [
                'allowed' => false,
                'reason' => 'unauthenticated',
                'user' => null,
                'role' => null,
                'access' => null,
            ];
        }

        $access = V3AccountAccess::where('user_id', $freshUser->id)->first();
        if (! $access) {
            return [
                'allowed' => false,
                'reason' => 'not_enrolled',
                'user' => $freshUser,
                'role' => $freshUser->role,
                'access' => null,
            ];
        }

        if (! $access->is_active) {
            return [
                'allowed' => false,
                'reason' => 'disabled',
                'user' => $freshUser,
                'role' => $freshUser->role,
                'access' => $access,
            ];
        }

        if (! in_array($freshUser->role, ['teacher', 'admin'], true)) {
            return [
                'allowed' => false,
                'reason' => 'wrong_role',
                'user' => $freshUser,
                'role' => $freshUser->role,
                'access' => $access,
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'active',
            'user' => $freshUser,
            'role' => $freshUser->role,
            'access' => $access,
        ];
    }
}
