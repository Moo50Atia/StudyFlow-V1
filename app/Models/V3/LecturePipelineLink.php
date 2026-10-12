<?php

namespace App\Models\V3;

use App\Models\Lecture;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LecturePipelineLink extends Model
{
    protected $table = 'v3_lecture_pipeline_links';

    protected $primaryKey = 'lecture_id';

    public $incrementing = false;

    protected $guarded = [];

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(Lecture::class, 'lecture_id');
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
