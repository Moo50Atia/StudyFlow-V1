<?php

namespace App\Contracts\V3;

interface V3OwnedResource
{
    /**
     * Get the stored user ID of the original owner.
     */
    public function v3OwnerId(): int;
}
