<?php

namespace App\Http\Controllers;

use App\Models\Lecture;
use App\Models\Section;
use App\Services\V3\WebPipeline\LinkedContentAccess;
use Illuminate\Http\Request;

class APIfilterController extends Controller
{
    public function getLectures($subjectId)
    {
        $query = Lecture::where('subject_id', $subjectId)->orderBy('title');
        $query = app(LinkedContentAccess::class)->scopeVisibleLectures($query, auth()->user());
        return $query->get(['id', 'title']);
    }

    public function getSections($lectureId)
    {
        $lecture = Lecture::findOrFail($lectureId);
        if (! app(LinkedContentAccess::class)->canAccessLecture(auth()->user(), $lecture)) {
            abort(403, 'Unauthorized access to pipeline-linked lecture sections.');
        }

        $query = Section::where('lecture_id', $lectureId)->orderBy('title');
        $query = app(LinkedContentAccess::class)->scopeVisibleSections($query, auth()->user());
        return $query->get(['id', 'title']);
    }
}
