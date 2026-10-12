<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lecture extends Model
{
    /** @use HasFactory<\Database\Factories\LectureFactory> */
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'title',
        'pdf_path',
        'mindmap_path',
        'summary',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function examQuestions()
    {
        return $this->hasMany(ExamQuestion::class);
    }

    /**
     * Users who loved this lecture.
     */
    public function lovedByUsers()
    {
        return $this->morphToMany(User::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }

    public function pipelineLink()
    {
        return $this->hasOne(\App\Models\V3\LecturePipelineLink::class, 'lecture_id');
    }
}
