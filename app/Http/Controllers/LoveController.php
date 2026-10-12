<?php

namespace App\Http\Controllers;

use App\Models\Lecture;
use App\Models\Question;
use App\Models\ExamQuestion;
use App\Models\Section;
use Illuminate\Http\Request;

class LoveController extends Controller
{
    /**
     * Toggle love status for an item.
     */
    public function toggle(Request $request)
    {
        $request->validate([
            'type' => 'required|in:lecture,question,exam_question,section',
            'id' => 'required|integer',
        ]);

        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (in_array($request->type, ['question', 'exam_question'], true) && ! app(\App\Services\LearningAccess::class)->isOwner($user)) abort(403);

        // Get the model based on type
        $model = match ($request->type) {
            'lecture' => Lecture::findOrFail($request->id),
            'question' => Question::findOrFail($request->id),
            'exam_question' => ExamQuestion::findOrFail($request->id),
            'section' => Section::findOrFail($request->id),
        };

        // Toggle love status
        $isLoved = $user->toggleLove($model);

        return response()->json([
            'success' => true,
            'is_loved' => $isLoved,
            'message' => $isLoved ? 'Added to favorites!' : 'Removed from favorites.',
        ]);
    }
}
