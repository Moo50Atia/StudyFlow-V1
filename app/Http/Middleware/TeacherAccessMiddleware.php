<?php

namespace App\Http\Middleware;

use App\Services\LearningAccess;
use App\Services\V3\WebPipeline\LinkedContentAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TeacherAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) abort(401);
        $access = app(LearningAccess::class);
        $linked = app(LinkedContentAccess::class);
        $resource = $request->route()->getName();
        if ($user->role === 'admin' && ! $access->isOwner($user)
            && (str_starts_with((string) $resource, 'subjects.') || str_starts_with((string) $resource, 'lectures.') || str_starts_with((string) $resource, 'sections.'))) {
            abort(403);
        }
        if (str_starts_with((string) $resource, 'questions.') || str_starts_with((string) $resource, 'exam_questions.')) {
            abort_unless($access->isOwner($user), 403);
        }
        $subject = $request->route('subject');
        $lecture = $request->route('lecture');
        $section = $request->route('section');
        if ($subject) {
            if ($user->role === 'student') {
                abort_unless($access->scopeSubjects(\App\Models\Subject::query(), $user)->whereKey($subject->id)->exists(), 403);
            } else {
                abort_unless($access->canManage($user, (int) $subject->id), 403);
            }
        }
        if ($lecture) abort_unless($linked->canAccessLecture($user, $lecture), 403);
        if ($section) abort_unless($linked->canAccessSection($user, $section), 403);
        if ($user->role === 'student') {
            abort_unless($request->isMethod('GET') && (str_ends_with((string) $resource, '.index') || str_ends_with((string) $resource, '.show')), 403);
        }
        return $next($request);
    }
}
