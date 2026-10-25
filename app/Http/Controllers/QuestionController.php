<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionRequest;
use App\Models\Question;
use App\Models\Lecture;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $query = Question::with('lecture.subject');

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

        $query->whereHas('lecture', function ($q) {
            app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($q, auth()->user());
        });

        $questions = $query->latest()->paginate(12)->withQueryString();

        // Get data for filters
        $subjects = Subject::orderBy('name')->get();
        $lectures = collect();

        // If subject is selected, get its lectures
        if ($request->filled('subject_id')) {
            $lecQuery = Lecture::where('subject_id', $request->subject_id)->orderBy('title');
            $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($lecQuery, auth()->user())->get();
        }

        return \App\Support\InertiaPage::render('questions.index', compact('questions', 'subjects', 'lectures'));
    }

    public function create()
    {
        $lecQuery = Lecture::with('subject')->orderBy('title');
        $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($lecQuery, auth()->user())->get();
        return \App\Support\InertiaPage::render('questions.create', compact('lectures'));
    }

    public function store(QuestionRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();

        $lecture = Lecture::findOrFail($data['lecture_id']);
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403);
        }

        // Handle question image upload
        if ($request->hasFile('question_image')) {
            $data['question_image'] = $request->file('question_image')->store('questions/images', 'local');
        }

        // Handle multiple solution images
        if ($request->hasFile('solution_images')) {
            $solutionImages = [];
            foreach ($request->file('solution_images') as $image) {
                $solutionImages[] = $image->store('questions/solutions', 'local');
            }
            $data['solution_images'] = $solutionImages;
        }

        Question::create($data);
        return redirect()->route('questions.index')->with('success', 'Question created successfully!');
    }

    public function show(Question $question)
    {
        if ($question->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $question->lecture)) {
            abort(403);
        }

        $question->load('lecture.subject');
        return \App\Support\InertiaPage::render('questions.show', compact('question'));
    }

    public function edit(Question $question)
    {
        if ($question->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $question->lecture)) {
            abort(403);
        }

        $lecQuery = Lecture::with('subject')->orderBy('title');
        $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($lecQuery, auth()->user())->get();
        return \App\Support\InertiaPage::render('questions.edit', compact('question', 'lectures'));
    }

    public function update(QuestionRequest $request, Question $question): \Illuminate\Http\RedirectResponse
    {
        if ($question->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $question->lecture)) {
            abort(403);
        }

        $data = $request->validated();

        if (isset($data['lecture_id']) && $data['lecture_id'] != $question->lecture_id) {
            $newLecture = Lecture::findOrFail($data['lecture_id']);
            if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $newLecture)) {
                abort(403);
            }
        }

        // Handle question image upload
        if ($request->hasFile('question_image')) {
            if ($question->question_image) {
                Storage::disk('local')->delete($question->question_image);
            }
            $data['question_image'] = $request->file('question_image')->store('questions/images', 'local');
        }

        // Handle multiple solution images
        if ($request->hasFile('solution_images')) {
            // Delete old images if replacing
            if ($question->solution_images) {
                foreach ($question->solution_images as $oldImage) {
                    Storage::disk('local')->delete($oldImage);
                }
            }
            $solutionImages = [];
            foreach ($request->file('solution_images') as $image) {
                $solutionImages[] = $image->store('questions/solutions', 'local');
            }
            $data['solution_images'] = $solutionImages;
        }

        $question->update($data);
        return redirect()->route('questions.index')->with('success', 'Question updated successfully!');
    }

    public function destroy(Question $question): \Illuminate\Http\RedirectResponse
    {
        if ($question->lecture && ! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $question->lecture)) {
            abort(403);
        }

        // Delete images if exist
        if ($question->question_image) {
            Storage::disk('local')->delete($question->question_image);
        }
        if ($question->solution_images) {
            foreach ($question->solution_images as $image) {
                Storage::disk('local')->delete($image);
            }
        }

        $question->delete();
        return redirect()->route('questions.index')->with('success', 'Question deleted successfully!');
    }
}
