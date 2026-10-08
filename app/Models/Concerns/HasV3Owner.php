<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\V3\Access\V3AccessResolver;
use Illuminate\Database\Eloquent\Builder;

trait HasV3Owner
{
    public static function bootHasV3Owner(): void
    {
        static::updating(function ($model) {
            if ($model->isDirty('owner_user_id')) {
                throw new \InvalidArgumentException('Modifying stored owner on update is prohibited.');
            }
        });
    }

    /**
     * Scope a query to only include resources owned by the active V3 teacher actor.
     */
    public function scopeOwnedBy(Builder $query, User $actor): Builder
    {
        $resolver = app(V3AccessResolver::class);
        $resolution = $resolver->resolve($actor);

        if (! $resolution['allowed'] || $resolution['role'] !== 'teacher') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->getTable().'.owner_user_id', $actor->id);
    }
}
