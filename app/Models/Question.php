<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'lecture_id',
        'question_text',
        'question_image',
        'idea_text',
        'hint',
        'solution_images',
        'solution_explanation',
        'dynamic_view_link',
    ];

    protected $casts = [
        'solution_images' => 'array',
    ];

    public function lecture()
    {
        return $this->belongsTo(Lecture::class);
    }

    /**
     * Users who loved this question.
     */
    public function lovedByUsers()
    {
        return $this->morphToMany(User::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }
}
