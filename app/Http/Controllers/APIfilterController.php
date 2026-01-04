<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lecture;
use App\Models\Section;

class APIfilterController extends Controller
{
    public function getLectures($subjectId)
    {
        return Lecture::where('subject_id', $subjectId)->orderBy('title')->get(['id', 'title']);
    }

    public function getSections($lectureId)
    {
        return Section::where('lecture_id', $lectureId)->orderBy('title')->get(['id', 'title']);
    }
}
