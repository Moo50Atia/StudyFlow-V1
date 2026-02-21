<?php

namespace App\Http\Controllers;

use App\Models\Lecture;
use Illuminate\Http\Request;
use App\Models\Section;
use App\Http\Resources\LectureResource;

class MohammedTestController extends Controller
{
    public function index()
    {
        // To Get All "core_concept" in Sections about Specific Subject 
        $sections = Section::whereHas('lecture.subject', function ($query) {
            $query->where('name', 'Electrical measurement');
        })->get("core_concept");

        // Get Better Structure With name under it The Sections Core Concept   
        $lectures = Lecture::whereHas("subject", function ($query) {
            $query->where("name", "Electrical measurement");
        })->with("sections")->get();
        // return view('mohammed-test');
        return response()->json(LectureResource::collection($lectures));
    }
}
