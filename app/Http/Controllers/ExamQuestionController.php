<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExamQuestionRequest;
use App\Models\ExamQuestion;
use App\Models\Lecture;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExamQuestionController extends Controller
{
    public function index(Request $request): \Illuminate\Contracts\View\View
    {
        $query = ExamQuestion::with('lecture.subject');

        $query->whereHas('lecture', function ($q) {
            app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($q, auth()->user());
        });

        // Filter by subject (via lecture)
        if ($request->filled('subject_id')) {
            $query->whereHas('lecture', function ($q) use ($request) {
                $q->where('subject_id', $request->subject_id);
            });
        }

        // Filter by lecture
        if ($request->filled('lecture_id')) {
            $query->where('lecture_id', $request->lecture_id);
        }

        $examQuestions = $query->latest()->paginate(12)->withQueryString();

        // Get data for filters
        $subjects = Subject::orderBy('name')->get();
        $lectures = collect();

        // If subject is selected, get its lectures
        if ($request->filled('subject_id')) {
            $lecQuery = Lecture::where('subject_id', $request->subject_id)->orderBy('title');
            $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($lecQuery, auth()->user())->get();
        }

        return view('exam_questions.index', compact('examQuestions', 'subjects', 'lectures'));
    }

    public function create(): \Illuminate\Contracts\View\View
    {
        $lecQuery = Lecture::with('subject')->orderBy('title');
        $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($lecQuery, auth()->user())->get();
        return view('exam_questions.create', compact('lectures'));
    }

    public function store(ExamQuestionRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();

        $lecture = Lecture::findOrFail($data['lecture_id']);
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403);
        }

        // Handle image uploads
        if ($request->hasFile('question_image')) {
            $data['question_image'] = $request->file('question_image')->store('exam_questions/images', 'local');
        }
        if ($request->hasFile('solution_image')) {
            $data['solution_image'] = $request->file('solution_image')->store('exam_questions/solutions', 'local');
        }

        ExamQuestion::create($data);
        return redirect()->route('exam_questions.index')->with('success', 'Exam Question created successfully!');
    }

    public function show(ExamQuestion $examQuestion): \Illuminate\Contracts\View\View
    {
        if ($examQuestion->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $examQuestion->lecture)) {
            abort(403);
        }

        $examQuestion->load('lecture.subject');
        return view('exam_questions.show', compact('examQuestion'));
    }

    public function edit(ExamQuestion $examQuestion): \Illuminate\Contracts\View\View
    {
        if ($examQuestion->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $examQuestion->lecture)) {
            abort(403);
        }

        $lecQuery = Lecture::with('subject')->orderBy('title');
        $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($lecQuery, auth()->user())->get();
        return view('exam_questions.edit', compact('examQuestion', 'lectures'));
    }

    public function update(ExamQuestionRequest $request, ExamQuestion $examQuestion): \Illuminate\Http\RedirectResponse
    {
        if ($examQuestion->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $examQuestion->lecture)) {
            abort(403);
        }

        $data = $request->validated();

        if (isset($data['lecture_id']) && $data['lecture_id'] != $examQuestion->lecture_id) {
            $newLecture = Lecture::findOrFail($data['lecture_id']);
            if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $newLecture)) {
                abort(403);
            }
        }

        // Handle image uploads
        if ($request->hasFile('question_image')) {
            if ($examQuestion->question_image) {
                Storage::disk('local')->delete($examQuestion->question_image);
            }
            $data['question_image'] = $request->file('question_image')->store('exam_questions/images', 'local');
        }
        if ($request->hasFile('solution_image')) {
            if ($examQuestion->solution_image) {
                Storage::disk('local')->delete($examQuestion->solution_image);
            }
            $data['solution_image'] = $request->file('solution_image')->store('exam_questions/solutions', 'local');
        }

        $examQuestion->update($data);
        return redirect()->route('exam_questions.index')->with('success', 'Exam Question updated successfully!');
    }

    public function destroy(ExamQuestion $examQuestion): \Illuminate\Http\RedirectResponse
    {
        if ($examQuestion->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $examQuestion->lecture)) {
            abort(403);
        }

        // Delete images if exist
        if ($examQuestion->question_image) {
            Storage::disk('local')->delete($examQuestion->question_image);
        }
        if ($examQuestion->solution_image) {
            Storage::disk('local')->delete($examQuestion->solution_image);
        }

        $examQuestion->delete();
        return redirect()->route('exam_questions.index')->with('success', 'Exam Question deleted successfully!');
    }
}
