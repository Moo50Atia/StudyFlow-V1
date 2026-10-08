<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * Teacher permissions relationship.
     */
    public function teacherPermissions()
    {
        return $this->hasMany(TeacherPermission::class, 'teacher_id');
    }

    /**
     * V3 Account Access relationship.
     */
    public function v3Access()
    {
        return $this->hasOne(\App\Models\V3\V3AccountAccess::class, 'user_id');
    }

    /**
     * Loved lectures.
     */
    public function lovedLectures()
    {
        return $this->morphedByMany(Lecture::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }

    /**
     * Loved questions.
     */
    public function lovedQuestions()
    {
        return $this->morphedByMany(Question::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }

    /**
     * Loved exam questions.
     */
    public function lovedExamQuestions()
    {
        return $this->morphedByMany(ExamQuestion::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }

    /**
     * Loved sections.
     */
    public function lovedSections()
    {
        return $this->morphedByMany(Section::class, 'loveable', 'user_loves')
            ->withTimestamps();
    }

    /**
     * Check if user loves a specific item.
     */
    public function loves($model): bool
    {
        $class = get_class($model);
        return DB::table('user_loves')
            ->where('user_id', $this->id)
            ->where('loveable_type', $class)
            ->where('loveable_id', $model->id)
            ->exists();
    }

    /**
     * Toggle love for an item.
     */
    public function toggleLove($model): bool
    {
        $class = get_class($model);
        $existing = DB::table('user_loves')
            ->where('user_id', $this->id)
            ->where('loveable_type', $class)
            ->where('loveable_id', $model->id)
            ->first();

        if ($existing) {
            DB::table('user_loves')->where('id', $existing->id)->delete();
            return false; // unloved
        } else {
            DB::table('user_loves')->insert([
                'user_id' => $this->id,
                'loveable_type' => $class,
                'loveable_id' => $model->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return true; // loved
        }
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
