<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    /** @use HasFactory<\Database\Factories\SubjectFactory> */
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'owner_user_id',
    ];
    public function lectures()
    {
        return $this->hasMany(Lecture::class);
    }

    public function teacherPermissions()
    {
        return $this->hasMany(TeacherPermission::class);
    }
}
