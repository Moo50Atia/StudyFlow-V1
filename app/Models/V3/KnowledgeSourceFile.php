<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSourceFile extends Model
{
    protected $table = 'v3_knowledge_source_files';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'artifact_bytes' => 'integer',
            'registered_at' => 'datetime',
            'ready_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSourceBinding::class, 'binding_id', 'id');
    }
}
