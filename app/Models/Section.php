<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    /** @use HasFactory<\Database\Factories\SectionFactory> */
    use HasFactory;

    protected $fillable = [
        'lecture_id',
        'title',
        'quick_summary',
        'core_concept',
        'egyptian_explain',
        'formulas',
        'real_life',
        'dynamic_view_link',
    ];

    public function lecture()
    {
        return $this->belongsTo(Lecture::class);
    }

    public function dynamicView()
    {
        return $this->hasOne(DynamicViewModel::class);
    }
}
