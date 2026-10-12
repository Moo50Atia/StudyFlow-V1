<?php

namespace App\Http\Controllers;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\V3\SubjectRun;
use App\Models\V3\TeacherSection;
use App\Services\LearningAccess;
use App\Services\V3\WebPipeline\LinkedContentAccess;
use App\Services\V3\WebPipeline\LessonProjection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $access = app(LearningAccess::class);
        if ($user->role === 'teacher') {
            $subjects = $access->scopeSubjects(Subject::query(), $user)->orderBy('name')->get();
            $runs = SubjectRun::with('items')->where('owner_user_id', $user->id)->latest()->limit(20)->get();
            $lessons = DB::table('v3_lesson_publications as p')
                ->join('lectures as l', 'l.id', '=', 'p.lecture_id')
                ->join('v3_lecture_pipeline_links as link', 'link.lecture_id', '=', 'l.id')
                ->join('v3_lesson_nodes as n', 'n.id', '=', 'p.work_node_id')
                ->where('link.owner_user_id', $user->id)
                ->select('p.*', 'l.title as lecture_title', 'n.title as lesson_title')->orderByDesc('p.created_at')->limit(30)->get();
            $this->decorateLessons($lessons);
            $scenes = $this->scenesForOwner($user->id);
            return view('dashboards.teacher', compact('subjects', 'runs', 'lessons', 'scenes'));
        }
        if ($user->role === 'student') {
            $subjects = $access->scopeSubjects(Subject::query(), $user)
                ->withCount(['lectures' => fn ($q) => app(LinkedContentAccess::class)->scopeVisibleLectures($q, $user)])
                ->orderBy('name')->get();
            $query = Lecture::with('subject')->latest();
            $lectures = app(LinkedContentAccess::class)->scopeVisibleLectures($query, $user)->limit(20)->get();
            $scenes = DB::table('v3_scene_publications as sp')
                ->join('v3_approved_scenes as a', 'a.id', '=', 'sp.approval_id')
                ->join('v3_teacher_sections as s', 's.id', '=', 'a.section_id')
                ->join('v3_lesson_publications as p', 'p.work_node_id', '=', 's.work_node_id')
                ->whereNotNull('p.published_at')->whereNotIn('a.id', DB::table('v3_postapproval_corrections')->select('approval_id'))
                ->select('a.id', 's.section_identity')->limit(20)->get();
            return view('dashboards.student', compact('subjects', 'lectures', 'scenes'));
        }
        if ($user->role === 'admin') {
            $isOwner = $access->isOwner($user);
            $runs = $isOwner ? SubjectRun::with('items')->latest()->limit(20)->get() : collect();
            $lessons = $isOwner ? DB::table('v3_lesson_publications as p')
                ->join('lectures as l', 'l.id', '=', 'p.lecture_id')
                ->join('v3_lesson_nodes as n', 'n.id', '=', 'p.work_node_id')
                ->select('p.*', 'l.title as lecture_title', 'n.title as lesson_title')->orderByDesc('p.created_at')->limit(30)->get() : collect();
            if ($isOwner) $this->decorateLessons($lessons);
            $scenes = $isOwner ? $this->scenesForOwner(null) : collect();
            return view('dashboards.admin', compact('isOwner', 'runs', 'lessons', 'scenes'));
        }
        abort(403);
    }

    private function decorateLessons($lessons): void
    {
        foreach ($lessons as $lesson) {
            $sections = TeacherSection::where('work_node_id', $lesson->work_node_id)->orderBy('ordinal_in_work')->get();
            $lesson->missing_fields = $sections->isEmpty() ? ['no sections'] : $sections->flatMap(fn ($s) => array_merge(
                $s->projection_status === 'complete' ? [] : ['source incomplete'],
                app(LessonProjection::class)->missing($s)
            ))->unique()->values()->all();
            $lesson->first_section_id = DB::table('v3_section_pipeline_links as l')
                ->join('v3_teacher_sections as s', 's.id', '=', 'l.teacher_section_id')
                ->where('s.work_node_id', $lesson->work_node_id)->orderBy('s.ordinal_in_work')->value('l.section_id');
        }
    }

    private function scenesForOwner(?int $userId)
    {
        $query = DB::table('v3_approved_scenes as a')
            ->join('v3_teacher_sections as s', 's.id', '=', 'a.section_id')
            ->join('v3_section_pipeline_links as l', 'l.teacher_section_id', '=', 's.id')
            ->leftJoin('v3_scene_publications as sp', 'sp.approval_id', '=', 'a.id')
            ->select('a.id', 's.section_identity', 'sp.published_at');
        if ($userId) $query->where('l.owner_user_id', $userId);
        return $query->limit(30)->get();
    }
}
