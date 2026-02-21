<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LectureResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "lecture" => $this->title,
            "lecture ID" => $this->id,
            "The Sections data" => $this->sections->map(function ($section) {
                return [
                    "title" => $section->title,
                    "core_concept" => $section->core_concept,
                    "end of core concept" => " ////////////////////////////////////////////////////////////////////////////////////////////////////",
                    "egyptian_explain" => $section->egyptian_explain,
                    "end of Egyptian Explain" => " ////////////////////////////////////////////////////////////////////////////////////////////////////",
                    "real_life" => $section->real_life,
                ];
            }),
            "questions" => $this->questions->map(function ($question) {
                return [
                    "question ID" => $question->id,
                    "question_text" => $question->question_text,
                    "end of question text" => " ////////////////////////////////////////////////////////////////////////////////////////////////////",
                    "idea_text" => $question->idea_text,
                    "end of idea text" => " ////////////////////////////////////////////////////////////////////////////////////////////////////",
                    "hint" => $question->hint,
                    "end of hint" => " ////////////////////////////////////////////////////////////////////////////////////////////////////",
                    "solution_explanation" => $question->solution_explanation,
                    "end of solution explanation" => " ////////////////////////////////////////////////////////////////////////////////////////////////////",
                ];
            })
        ];
    }
}
