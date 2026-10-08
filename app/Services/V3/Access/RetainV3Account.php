<?php

namespace App\Services\V3\Access;

use App\Models\User;
use App\Models\V3\V3AccountAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RetainV3Account
{
    public function assertCanDelete(User $user): void
    {
        if (V3AccountAccess::where('user_id', $user->id)->exists()) {
            throw new HttpException(409, 'V3 accounts cannot be deleted and must be retained.');
        }
    }
}
