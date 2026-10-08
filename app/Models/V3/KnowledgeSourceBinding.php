<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSourceBinding extends Model
{
    protected $table = 'v3_knowledge_source_bindings';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'text_bytes' => 'integer',
            'send_attempted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (KnowledgeSourceBinding $model) {
            foreach (['id', 'case_id', 'extracted_source_id', 'adapter_kind'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on KnowledgeSourceBinding is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function extractedSource(): BelongsTo
    {
        return $this->belongsTo(ExtractedSource::class, 'extracted_source_id', 'id');
    }

    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(NotebookFrameworkVersion::class, 'framework_version_id', 'id');
    }
}
