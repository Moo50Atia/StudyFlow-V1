<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\Section;
use App\Models\User;
use App\Models\V3\LecturePipelineLink;
use App\Models\V3\SectionPipelineLink;
use Illuminate\Database\Eloquent\Builder;

class LinkedContentAccess
{
    /**
     * Determine if a lecture is linked to the V3 pipeline.
     */
    public function isLectureLinked(Lecture|int|string $lecture): bool
    {
        $lectureId = $lecture instanceof Lecture ? $lecture->id : $lecture;
        return LecturePipelineLink::where('lecture_id', $lectureId)->exists();
    }

    /**
     * Get the owner user ID of a linked lecture, or null if unlinked.
     */
    public function getLectureOwnerId(Lecture|int|string $lecture): ?int
    {
        $lectureId = $lecture instanceof Lecture ? $lecture->id : $lecture;
        $link = LecturePipelineLink::where('lecture_id', $lectureId)->first();
        return $link ? (int) $link->owner_user_id : null;
    }

    /**
     * Determine if a section is linked to the V3 pipeline.
     */
    public function isSectionLinked(Section|int|string $section): bool
    {
        $sectionId = $section instanceof Section ? $section->id : $section;
        return SectionPipelineLink::where('section_id', $sectionId)->exists();
    }

    /**
     * Get the owner user ID of a linked section, or null if unlinked.
     */
    public function getSectionOwnerId(Section|int|string $section): ?int
    {
        $sec = $section instanceof Section ? $section : Section::find($section);
        if (! $sec) {
            return null;
        }

        $link = SectionPipelineLink::where('section_id', $sec->id)->first();
        if ($link) {
            return (int) $link->owner_user_id;
        }

        if ($sec->lecture_id) {
            return $this->getLectureOwnerId($sec->lecture_id);
        }

        return null;
    }

    /**
     * Check if a user can access a lecture.
     */
    public function canAccessLecture(?User $user, Lecture|int|string $lecture): bool
    {
        $lecture = $lecture instanceof Lecture ? $lecture : Lecture::find($lecture);
        if (! $lecture || ! $user) return false;
        $ownerId = $this->getLectureOwnerId($lecture);
        if ($ownerId === null) {
            if ($user->role === 'student') return $lecture->subject?->owner_user_id === null;
            if ($user->role === 'admin') return app(\App\Services\LearningAccess::class)->isOwner($user);
            return app(\App\Services\LearningAccess::class)->canTeach($user, (int) $lecture->subject_id);
        }
        if ($user->role === 'student') {
            return \Illuminate\Support\Facades\DB::table('v3_lesson_publications')
                ->where('lecture_id', $lecture->id)->whereNotNull('published_at')->exists();
        }
        if (app(\App\Services\LearningAccess::class)->isOwner($user)) return true;
        return $user->role === 'teacher' && (int) $user->id === $ownerId;
    }

    /**
     * Check if a user can access a section.
     */
    public function canAccessSection(?User $user, Section|int|string $section): bool
    {
        $section = $section instanceof Section ? $section : Section::find($section);
        if (! $section || ! $user) return false;
        if (! $this->canAccessLecture($user, $section->lecture)) return false;
        $ownerId = $this->getSectionOwnerId($section);
        if ($ownerId === null) return true;
        if ($user->role === 'student') {
            return \Illuminate\Support\Facades\DB::table('v3_section_pipeline_links as l')
                ->join('v3_teacher_sections as s', 's.id', '=', 'l.teacher_section_id')
                ->join('v3_lesson_publications as p', 'p.work_node_id', '=', 's.work_node_id')
                ->where('l.section_id', $section->id)->whereNotNull('p.published_at')->exists();
        }
        return app(\App\Services\LearningAccess::class)->isOwner($user) || (int) $user->id === $ownerId;
    }

    /**
     * Scope a Lecture query to exclude pipeline-linked lectures belonging to other users.
     */
    public function scopeVisibleLectures(Builder|\Illuminate\Database\Eloquent\Relations\Relation $query, ?User $user): Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        if (app(\App\Services\LearningAccess::class)->isOwner($user)) return $query;
        if ($user?->role === 'student') {
            return $query->where(fn ($q) => $q->where(fn ($u) => $u->whereDoesntHave('pipelineLink')->whereHas('subject', fn ($s) => $s->whereNull('owner_user_id')))
                ->orWhereHas('pipelineLink', fn ($l) => $l->whereIn('lecture_id', \Illuminate\Support\Facades\DB::table('v3_lesson_publications')->whereNotNull('published_at')->select('lecture_id'))));
        }
        if ($user?->role === 'teacher') {
            return $query->where(fn ($q) => $q->whereHas('subject', fn ($s) => $s->where('owner_user_id', $user->id)
                ->orWhereHas('teacherPermissions', fn ($p) => $p->where('teacher_id', $user->id)))
                ->where(fn ($q) => $q->whereDoesntHave('pipelineLink')->orWhereHas('pipelineLink', fn ($l) => $l->where('owner_user_id', $user->id))));
        }
        return $query->whereRaw('1 = 0');
    }

    /**
     * Scope a Section query to exclude pipeline-linked sections belonging to other users.
     */
    public function scopeVisibleSections(Builder|\Illuminate\Database\Eloquent\Relations\Relation $query, ?User $user): Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        if (app(\App\Services\LearningAccess::class)->isOwner($user)) return $query;
        $visibleLectures = Lecture::query();
        $this->scopeVisibleLectures($visibleLectures, $user);
        $query->whereIn('lecture_id', $visibleLectures->select('id'));
        if ($user?->role === 'student') {
            $query->where(fn ($q) => $q->whereDoesntHave('pipelineLink')->whereHas('lecture.subject', fn ($s) => $s->whereNull('owner_user_id'))
                ->orWhereHas('pipelineLink', fn ($l) => $l->whereIn('teacher_section_id', \Illuminate\Support\Facades\DB::table('v3_teacher_sections as s')
                    ->join('v3_lesson_publications as p', 'p.work_node_id', '=', 's.work_node_id')
                    ->whereNotNull('p.published_at')->select('s.id'))));
        }
        return $query;
    }
}
