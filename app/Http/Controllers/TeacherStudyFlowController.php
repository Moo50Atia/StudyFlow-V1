<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkSectionRequest;
use App\Http\Requests\BulkContentRequest;
use App\Models\Subject;
use App\Models\Lecture;
use App\Models\Section;
use App\Models\Question;
use App\Models\ExamQuestion;
use App\Models\TeacherPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeacherStudyFlowController extends Controller
{
    /**
     * Start the teacher study flow wizard
     */
    public function start()
    {
        session(['study_flow' => true]);
        return redirect()->route('teacher-study-flow.lectures')
            ->with('info', 'Teacher Study Flow started! Select or create a lecture to begin.');
    }

    /**
     * Show lectures the teacher has permission to access
     */
    public function lectures()
    {
        $user = Auth::user();

        // Get teacher's permissions
        $permissions = TeacherPermission::where('teacher_id', $user->id)
            ->with(['subject', 'lecture'])
            ->get();

        // Get permitted subjects and lectures
        $allowedSubjectIds = $permissions->pluck('subject_id')->unique()->filter();
        $allowedLectureIds = $permissions->pluck('lecture_id')->unique()->filter();

        $subjects = Subject::whereIn('id', $allowedSubjectIds)->get();
        $lectures = Lecture::whereIn('id', $allowedLectureIds)
            ->orWhereIn('subject_id', $allowedSubjectIds)
            ->with('subject')
            ->get();

        return view('teacher-study-flow.lectures', compact('subjects', 'lectures', 'permissions'));
    }

    /**
     * Create a new lecture (only in permitted subjects)
     */
    public function storeLecture(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string',
        ]);

        // Check permission for subject
        $hasPermission = TeacherPermission::where('teacher_id', $user->id)
            ->where('subject_id', $request->subject_id)
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to create lectures in this subject.');
        }

        $lecture = Lecture::create([
            'subject_id' => $request->subject_id,
            'title' => $request->title,
            'summary' => $request->summary,
        ]);

        // Auto-grant permission to the lecture
        TeacherPermission::create([
            'teacher_id' => $user->id,
            'subject_id' => $request->subject_id,
            'lecture_id' => $lecture->id,
        ]);

        return redirect()->route('lectures.show', $lecture)
            ->with('success', 'Lecture created successfully! Now add sections.');
    }

    /**
     * Bulk create sections for a lecture (teacher flow)
     */
    public function storeBulkSections(BulkSectionRequest $request, Lecture $lecture)
    {
        $user = Auth::user();

        // Check permission
        $hasPermission = TeacherPermission::where('teacher_id', $user->id)
            ->where(function ($query) use ($lecture) {
                $query->where('lecture_id', $lecture->id)
                    ->orWhere('subject_id', $lecture->subject_id);
            })
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to modify this lecture.');
        }

        $count = 0;
        foreach ($request->sections as $sectionData) {
            $lecture->sections()->create([
                'title' => $sectionData['title'],
                'quick_summary' => $sectionData['quick_summary'] ?? null,
                'core_concept' => $sectionData['core_concept'] ?? null,
                'egyptian_explain' => $sectionData['egyptian_explain'] ?? null,
                'formulas' => $sectionData['formulas'] ?? null,
                'real_life' => $sectionData['real_life'] ?? null,
                'dynamic_view_link' => $sectionData['dynamic_view_link'] ?? null,
            ]);
            $count++;
        }

        return redirect()->route('lectures.show', $lecture)
            ->with('success', $count . ' section(s) created successfully!');
    }

    /**
     * Bulk create content for a section (teacher flow)
     */
    public function storeBulkContent(BulkContentRequest $request, Section $section)
    {
        $user = Auth::user();

        // Check permission via lecture
        $hasPermission = TeacherPermission::where('teacher_id', $user->id)
            ->where(function ($query) use ($section) {
                $query->where('lecture_id', $section->lecture_id)
                    ->orWhere('subject_id', $section->lecture->subject_id);
            })
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to modify this section.');
        }

        $messages = [];

        // Update section links if provided
        if ($request->dynamic_view_link) {
            $section->update([
                'dynamic_view_link' => $request->dynamic_view_link ?? $section->dynamic_view_link,
            ]);
            $messages[] = 'Links updated';
        }

        // Create questions
        if ($request->questions && count($request->questions) > 0) {
            foreach ($request->questions as $questionData) {
                Question::create([
                    'lecture_id' => $section->lecture_id,
                    'idea_text' => $questionData['idea'],
                    'solution_explanation' => $questionData['explanation'] ?? null,
                    'dynamic_view_link' => $questionData['dynamic_view_link'] ?? null,
                ]);
            }
            $messages[] = count($request->questions) . ' question(s) added';
        }

        // Create exam questions
        if ($request->exam_questions && count($request->exam_questions) > 0) {
            foreach ($request->exam_questions as $examData) {
                ExamQuestion::create([
                    'lecture_id' => $section->lecture_id,
                    'idea' => $examData['idea'],
                    'explanation' => $examData['explanation'] ?? null,
                    'dynamic_view_link' => $examData['dynamic_view_link'] ?? null,
                ]);
            }
            $messages[] = count($request->exam_questions) . ' exam question(s) added';
        }

        $message = count($messages) > 0 ? implode(', ', $messages) : 'No changes made';

        return redirect()->route('sections.show', $section)
            ->with('success', $message);
    }

    /**
     * Exit the teacher study flow wizard
     */
    public function exit()
    {
        session()->forget('study_flow');
        return redirect()->route('dashboard')
            ->with('success', 'Teacher Study Flow completed!');
    }
}
