<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model

{
    /** @use HasFactory<\Database\Factories\ExamQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'lecture_id',
        'question_image',
        'idea',
        'solution_image',
        'explanation',
        'dynamic_view_link',
    ];

    public function lecture()
    {
        return $this->belongsTo(Lecture::class);
    }

    /**
     * Users who loved this exam question.
     */
    public function lovedByUsers()
    {
        return $this->morphToMany(User::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }
}
