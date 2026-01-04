<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkLectureRequest;
use App\Http\Requests\BulkSectionRequest;
use App\Http\Requests\BulkContentRequest;
use App\Models\Subject;
use App\Models\Lecture;
use App\Models\Section;
use App\Models\Question;
use App\Models\ExamQuestion;
use Illuminate\Http\Request;

class StudyFlowController extends Controller
{
    /**
     * Start the study flow wizard
     */
    public function start()
    {
        session(['study_flow' => true]);
        return redirect()->route('subjects.index')
            ->with('info', 'Study Flow started! Select or create a subject to begin.');
    }

    /**
     * Bulk create lectures for a subject
     */
    public function storeBulkLectures(BulkLectureRequest $request, Subject $subject)
    {
        $count = 0;
        foreach ($request->lectures as $lectureData) {
            $subject->lectures()->create([
                'title' => $lectureData['title'],
                'summary' => $lectureData['summary'] ?? null,
            ]);
            $count++;
        }

        return redirect()->route('subjects.show', $subject)
            ->with('success', $count . ' lecture(s) created successfully!');
    }

    /**
     * Bulk create sections for a lecture
     */
    public function storeBulkSections(BulkSectionRequest $request, Lecture $lecture)
    {
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
     * Bulk create content for a section (questions, exam questions, links)
     */
    public function storeBulkContent(BulkContentRequest $request, Section $section)
    {
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
     * Exit the study flow wizard
     */
    public function exit()
    {
        session()->forget('study_flow');
        return redirect()->route('dashboard')
            ->with('success', 'Study Flow completed!');
    }
}
