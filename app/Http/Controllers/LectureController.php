<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\LectureRequest;
use App\Models\Lecture;
use App\Models\Subject;
use Illuminate\Support\Facades\Storage;

class LectureController extends Controller
{
    public function index()
    {
        $query = Lecture::with('subject')->latest();
        $query = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($query, auth()->user());
        $lectures = $query->paginate(10);
        return \App\Support\InertiaPage::render('lectures.index', compact('lectures'));
    }

    public function create()
    {
        $subjects = app(\App\Services\LearningAccess::class)->scopeSubjects(Subject::query(), auth()->user())->orderBy('name')->get();
        return \App\Support\InertiaPage::render('lectures.create', compact('subjects'));
    }

    public function store(LectureRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();
        abort_unless(app(\App\Services\LearningAccess::class)->canManage($request->user(), (int) $data['subject_id']), 403);
        abort_if($request->hasFile('mindmap_path') && ! app(\App\Services\LearningAccess::class)->isOwner($request->user()), 403);

        // Handle PDF upload
        if ($request->hasFile('pdf_path')) {
            $data['pdf_path'] = $request->file('pdf_path')->store('lectures/pdfs', 'public');
        }

        // Handle Mind Map upload
        if ($request->hasFile('mindmap_path')) {
            $data['mindmap_path'] = $request->file('mindmap_path')->store('lectures/mindmaps', 'local');
        }

        $lecture = Lecture::create($data);
        if ($request->user()->role === 'teacher') \App\Models\TeacherPermission::firstOrCreate(['teacher_id' => $request->user()->id, 'subject_id' => $lecture->subject_id, 'lecture_id' => $lecture->id]);
        return redirect()->route('lectures.index')->with('success', 'Lecture created successfully!');
    }

    public function show(Lecture $lecture)
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403);
        }

        $lecture->load(['subject', 'sections' => fn ($q) => app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleSections($q, auth()->user())]);
        
        $nextQuery = Lecture::where('subject_id', $lecture->subject_id)
            ->where('id', '>', $lecture->id)
            ->orderBy('id', 'asc');
        $nextLecture = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($nextQuery, auth()->user())->first();

        return \App\Support\InertiaPage::render('lectures.show', compact('lecture', 'nextLecture'));
    }

    public function edit(Lecture $lecture)
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403);
        }

        $subjects = app(\App\Services\LearningAccess::class)->scopeSubjects(Subject::query(), auth()->user())->orderBy('name')->get();
        return \App\Support\InertiaPage::render('lectures.edit', compact('lecture', 'subjects'));
    }

    public function update(LectureRequest $request, Lecture $lecture): \Illuminate\Http\RedirectResponse
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403);
        }

        $data = $request->validated();
        abort_unless(app(\App\Services\LearningAccess::class)->canManage($request->user(), (int) $data['subject_id']), 403);
        abort_if($request->hasFile('mindmap_path') && ! app(\App\Services\LearningAccess::class)->isOwner($request->user()), 403);
        abort_if($lecture->pipelineLink && ((int) $data['subject_id'] !== (int) $lecture->subject_id || $request->hasFile('pdf_path')), 422);

        // Handle PDF upload
        if ($request->hasFile('pdf_path')) {
            if ($lecture->pdf_path) {
                Storage::disk('public')->delete($lecture->pdf_path);
            }
            $data['pdf_path'] = $request->file('pdf_path')->store('lectures/pdfs', 'public');
        }

        // Handle Mind Map upload
        if ($request->hasFile('mindmap_path')) {
            if ($lecture->mindmap_path) {
                Storage::disk('local')->delete($lecture->mindmap_path);
            }
            $data['mindmap_path'] = $request->file('mindmap_path')->store('lectures/mindmaps', 'local');
        }

        $changedContent = $lecture->pipelineLink && (($data['title'] ?? $lecture->title) !== $lecture->title || ($data['summary'] ?? $lecture->summary) !== $lecture->summary);
        \Illuminate\Support\Facades\DB::transaction(function () use ($lecture, $data, $changedContent, $request) {
            $lecture->update($data);
            if ($changedContent) {
                foreach ($lecture->sections()->whereHas('pipelineLink')->pluck('id') as $sectionId) {
                    app(\App\Services\V3\WebPipeline\LessonPublication::class)->revokeForLegacySection($sectionId, $request->user()->id, ['lecture_fields' => ['title', 'summary']]);
                }
            }
        });
        return redirect()->route('lectures.index')->with('success', 'Lecture updated successfully!');
    }

    public function destroy(Lecture $lecture): \Illuminate\Http\RedirectResponse
    {
        abort_if($lecture->mindmap_path && ! app(\App\Services\LearningAccess::class)->isOwner(auth()->user()), 403);
        abort_if(! app(\App\Services\LearningAccess::class)->isOwner(auth()->user())
            && ($lecture->questions()->exists() || $lecture->examQuestions()->exists()), 403);
        abort_if($lecture->pipelineLink, 403);
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403);
        }
        // Delete PDF file if exists
        if ($lecture->pdf_path) {
            Storage::disk('public')->delete($lecture->pdf_path);
        }

        // Delete Mind Map file if exists
        if ($lecture->mindmap_path) {
            Storage::disk('local')->delete($lecture->mindmap_path);
        }

        $lecture->delete();
        return redirect()->route('lectures.index')->with('success', 'Lecture deleted successfully!');
    }
}
