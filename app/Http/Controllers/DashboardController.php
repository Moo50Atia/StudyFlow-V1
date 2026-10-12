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
            return view('dashboards.teacher', compact('subjects', 'runs', 'lessons'));
        }
        if ($user->role === 'student') {
            $subjects = $access->scopeSubjects(Subject::query(), $user)->withCount('lectures')->orderBy('name')->get();
            $query = Lecture::with('subject')->latest();
            $lectures = app(LinkedContentAccess::class)->scopeVisibleLectures($query, $user)->limit(20)->get();
            return view('dashboards.student', compact('subjects', 'lectures'));
        }
        if ($user->role === 'admin') {
            $isOwner = $access->isOwner($user);
            $runs = $isOwner ? SubjectRun::with('items')->latest()->limit(20)->get() : collect();
            $lessons = $isOwner ? DB::table('v3_lesson_publications as p')
                ->join('lectures as l', 'l.id', '=', 'p.lecture_id')
                ->join('v3_lesson_nodes as n', 'n.id', '=', 'p.work_node_id')
                ->select('p.*', 'l.title as lecture_title', 'n.title as lesson_title')->orderByDesc('p.created_at')->limit(30)->get() : collect();
            if ($isOwner) $this->decorateLessons($lessons);
            return view('dashboards.admin', compact('isOwner', 'runs', 'lessons'));
        }
        abort(403);
    }

    private function decorateLessons($lessons): void
    {
        foreach ($lessons as $lesson) {
            $sections = TeacherSection::where('work_node_id', $lesson->work_node_id)->orderBy('ordinal_in_work')->get();
            $lesson->missing_fields = $sections->isEmpty() ? ['no sections'] : $sections->flatMap(fn ($s) => app(LessonProjection::class)->missing($s))->unique()->values()->all();
            $lesson->first_section_id = DB::table('v3_section_pipeline_links as l')
                ->join('v3_teacher_sections as s', 's.id', '=', 'l.teacher_section_id')
                ->where('s.work_node_id', $lesson->work_node_id)->orderBy('s.ordinal_in_work')->value('l.section_id');
        }
    }
}
