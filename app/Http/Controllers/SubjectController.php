<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectRequest;
use App\Models\Subject;

class SubjectController extends Controller
{
    public function index(): \Illuminate\Contracts\View\View
    {
        $subjects = app(\App\Services\LearningAccess::class)->scopeSubjects(Subject::query(), auth()->user())->latest()->paginate(10);
        return view('subjects.index', compact('subjects'));
    }

    public function create(): \Illuminate\Contracts\View\View
    {
        return view('subjects.create');
    }

    public function store(SubjectRequest $request): \Illuminate\Http\RedirectResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $subject = Subject::create($request->validated() + ['owner_user_id' => $request->user()->role === 'teacher' ? $request->user()->id : null]);
            if ($request->user()->role === 'teacher') {
                \App\Models\TeacherPermission::firstOrCreate([
                    'teacher_id' => $request->user()->id, 'subject_id' => $subject->id, 'lecture_id' => null,
                ]);
            }
        });
        return redirect()->route('subjects.index')->with('success', 'Subject created successfully!');
    }

    public function show(Subject $subject): \Illuminate\Contracts\View\View
    {
        $subject->load(['lectures' => function ($query) {
            app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($query, auth()->user());
        }]);
        return view('subjects.show', compact('subject'));
    }

    public function edit(Subject $subject): \Illuminate\Contracts\View\View
    {
        return view('subjects.edit', compact('subject'));
    }

    public function update(SubjectRequest $request, Subject $subject): \Illuminate\Http\RedirectResponse
    {
        $subject->update($request->validated());
        return redirect()->route('subjects.index')->with('success', 'Subject updated successfully!');
    }

    public function destroy(Subject $subject): \Illuminate\Http\RedirectResponse
    {
        $subject->delete();
        return redirect()->route('subjects.index')->with('success', 'Subject deleted successfully!');
    }
}
