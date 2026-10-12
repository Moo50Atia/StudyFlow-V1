<?php

namespace App\Http\Controllers;

use App\Models\ExamQuestion;
use App\Models\Lecture;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OwnerAssetController extends Controller
{
    public function show(Request $request, string $kind, int $id, string $field, int $index = 0)
    {
        abort_unless(app(\App\Services\LearningAccess::class)->isOwner($request->user()), 403);
        $model = match ($kind) {
            'lecture' => Lecture::findOrFail($id),
            'question' => Question::findOrFail($id),
            'exam-question' => ExamQuestion::findOrFail($id),
            default => abort(404),
        };
        $allowed = match ($kind) {
            'lecture' => ['mindmap_path'],
            'question' => ['question_image', 'solution_images'],
            'exam-question' => ['question_image', 'solution_image'],
        };
        abort_unless(in_array($field, $allowed, true), 404);
        $path = $model->{$field};
        if (is_array($path)) $path = $path[$index] ?? null;
        abort_unless(is_string($path) && preg_match('#^(lectures/mindmaps|questions/(images|solutions)|exam_questions/(images|solutions))/[A-Za-z0-9._-]+$#', $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
