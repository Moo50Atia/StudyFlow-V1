<?php

namespace App\Services;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LearningAccess
{
    public function isOwner(?User $user): bool
    {
        return $user?->role === 'admin' && strcasecmp((string) $user->email, 'admin@test.com') === 0;
    }

    public function canTeach(?User $user, int $subjectId): bool
    {
        if (! $user || $user->role !== 'teacher') return false;
        return Subject::whereKey($subjectId)->where('owner_user_id', $user->id)->exists()
            || DB::table('teacher_permissions')->where('teacher_id', $user->id)->where('subject_id', $subjectId)->exists();
    }

    public function canManage(?User $user, int $subjectId): bool
    {
        return $this->isOwner($user) || $this->canTeach($user, $subjectId);
    }

    public function canManageProcessingDevices(?User $user): bool
    {
        return $this->isOwner($user);
    }

    public function scopeSubjects(Builder $query, ?User $user): Builder
    {
        if ($this->isOwner($user)) return $query;
        if ($user?->role === 'teacher') {
            return $query->where(fn ($q) => $q->where('owner_user_id', $user->id)
                ->orWhereHas('teacherPermissions', fn ($p) => $p->where('teacher_id', $user->id)));
        }
        if ($user?->role === 'admin') return $query->whereRaw('1 = 0');
        return $query->whereHas('lectures', fn ($q) => app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($q, $user));
    }
}
