<?php

namespace App\Http\Controllers;

use App\Http\Resources\LectureResource;
use App\Models\Lecture;
use App\Models\Section;
use App\Services\V3\WebPipeline\LinkedContentAccess;
use Illuminate\Http\Request;

class MohammedTestController extends Controller
{
    public function index()
    {
        $linkedAccess = app(LinkedContentAccess::class);

        // To Get All "core_concept" in Sections about Specific Subject 
        $secQuery = Section::whereHas('lecture.subject', function ($query) {
            $query->where('name', 'Electrical measurement');
        });
        $sections = $linkedAccess->scopeVisibleSections($secQuery, auth()->user())->get("core_concept");

        // Get Better Structure With name under it The Sections Core Concept   
        $lecQuery = Lecture::whereHas("subject", function ($query) {
            $query->where("name", "Electrical measurement");
        })->with("sections");
        $lectures = $linkedAccess->scopeVisibleLectures($lecQuery, auth()->user())->get();

        return response()->json(LectureResource::collection($lectures));
    }
}
