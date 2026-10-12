<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SectionRequest;
use App\Models\Section;
use App\Models\Lecture;

class SectionController extends Controller
{
    public function index(): \Illuminate\Contracts\View\View
    {
        $query = Section::with('lecture.subject')->latest();
        $query = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleSections($query, auth()->user());
        $sections = $query->paginate(10);
        return view('sections.index', compact('sections'));
    }

    public function create(): \Illuminate\Contracts\View\View
    {
        $query = Lecture::with('subject')->orderBy('title');
        $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($query, auth()->user())->get();
        return view('sections.create', compact('lectures'));
    }

    public function store(SectionRequest $request): \Illuminate\Http\RedirectResponse
    {
        $lecture = Lecture::findOrFail($request->validated('lecture_id'));
        abort_unless(app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessLecture($request->user(), $lecture), 403);
        Section::create($request->validated());
        return redirect()->route('sections.index')->with('success', 'Section created successfully!');
    }

    public function show(Section $section): \Illuminate\Contracts\View\View
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessSection(auth()->user(), $section)) {
            abort(403);
        }

        $section->load(['lecture.subject', 'pipelineLink.teacherSection', 'lecture.sections' => fn ($q) => app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleSections($q, auth()->user())]);
        
        $nextSectionQuery = Section::where('lecture_id', $section->lecture_id)
            ->where('id', '>', $section->id)
            ->orderBy('id', 'asc');
        $nextSection = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleSections($nextSectionQuery, auth()->user())->first();

        $nextLectureQuery = Lecture::where('subject_id', $section->lecture->subject_id)
            ->where('id', '>', $section->lecture_id)
            ->orderBy('id', 'asc');
        $nextLecture = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($nextLectureQuery, auth()->user())->first();

        return view('sections.show', compact('section', 'nextSection', 'nextLecture'));
    }

    public function edit(Section $section): \Illuminate\Contracts\View\View
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessSection(auth()->user(), $section)) {
            abort(403);
        }

        $query = Lecture::with('subject')->orderBy('title');
        $lectures = app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->scopeVisibleLectures($query, auth()->user())->get();
        return view('sections.edit', compact('section', 'lectures'));
    }

    public function update(SectionRequest $request, Section $section): \Illuminate\Http\RedirectResponse
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessSection(auth()->user(), $section)) {
            abort(403);
        }

        abort_unless((int) $request->validated('lecture_id') === (int) $section->lecture_id, 422);
        $before = $section->only(['title', 'quick_summary', 'core_concept', 'egyptian_explain', 'formulas', 'real_life']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($section, $request, $before) {
            $section->update($request->validated());
            $changed = array_diff_assoc($section->only(array_keys($before)), $before);
            if ($changed) app(\App\Services\V3\WebPipeline\LessonPublication::class)->revokeForLegacySection($section->id, $request->user()->id, ['changed_fields' => array_keys($changed)]);
        });
        return redirect()->route('sections.index')->with('success', 'Section updated successfully!');
    }

    public function destroy(Section $section): \Illuminate\Http\RedirectResponse
    {
        if (! app(\App\Services\V3\WebPipeline\LinkedContentAccess::class)->canAccessSection(auth()->user(), $section)) {
            abort(403);
        }

        abort_if($section->pipelineLink, 403);
        $section->delete();
        return redirect()->route('sections.index')->with('success', 'Section deleted successfully!');
    }
}
