<?php

namespace App\Models\V3;

use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionPipelineLink extends Model
{
    protected $table = 'v3_section_pipeline_links';

    protected $primaryKey = 'section_id';

    public $incrementing = false;

    protected $guarded = [];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function teacherSection(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'teacher_section_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubjectRunItem::class, 'item_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
