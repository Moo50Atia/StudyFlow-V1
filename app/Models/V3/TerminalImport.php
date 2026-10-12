<?php

namespace App\Models\V3;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TerminalImport extends Model
{
    protected $table = 'v3_terminal_imports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'committed_item_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_admin_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function destinationRun(): BelongsTo
    {
        return $this->belongsTo(SubjectRun::class, 'destination_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TerminalImportItem::class, 'import_id')->orderBy('pdf_ordinal');
    }
}
