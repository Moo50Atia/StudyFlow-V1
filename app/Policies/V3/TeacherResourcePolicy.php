<?php

namespace App\Policies\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\User;
use App\Services\V3\Access\V3AccessResolver;

class TeacherResourcePolicy
{
    public function __construct(
        protected V3AccessResolver $resolver
    ) {}

    public function view(User $user, V3OwnedResource $resource): bool
    {
        return $this->owns($user, $resource);
    }

    public function update(User $user, V3OwnedResource $resource): bool
    {
        return $this->owns($user, $resource);
    }

    public function review(User $user, V3OwnedResource $resource): bool
    {
        return $this->owns($user, $resource);
    }

    public function download(User $user, V3OwnedResource $resource): bool
    {
        return $this->owns($user, $resource);
    }

    protected function owns(User $user, V3OwnedResource $resource): bool
    {
        $resolution = $this->resolver->resolve($user);

        if (! $resolution['allowed'] || $resolution['role'] !== 'teacher') {
            return false;
        }

        return (int) $user->id === (int) $resource->v3OwnerId();
    }
}
