<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Models\V3\LessonNode;
use App\Services\LearningAccess;
use App\Services\V3\WebPipeline\LessonPublication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonPublicationController extends Controller
{
    public function update(Request $request, LessonNode $node, LessonPublication $publication)
    {
        $record = DB::table('v3_lesson_publications')->where('work_node_id', $node->id)->first();
        abort_unless($record, 404);
        $lecture = \App\Models\Lecture::findOrFail($record->lecture_id);
        $access = app(LearningAccess::class);
        abort_unless($access->isOwner($request->user()) || (int) $lecture->pipelineLink?->owner_user_id === (int) $request->user()->id, 403);
        $data = $request->validate(['published' => 'required|boolean']);
        $publication->set($node->id, $request->user()->id, (bool) $data['published']);
        return back()->with('success', $data['published'] ? 'Lesson published.' : 'Lesson unpublished.');
    }
}
