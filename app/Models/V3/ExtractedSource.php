<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExtractedSource extends Model
{
    protected $table = 'v3_extracted_sources';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings_version_id' => 'integer',
            'text_bytes' => 'integer',
            'page_count' => 'integer',
            'ocr_page_count' => 'integer',
            'page_report' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (ExtractedSource $model) {
            foreach (['id', 'case_id', 'activation_id', 'document_id', 'settings_version_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on ExtractedSource is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function sourceActivation(): BelongsTo
    {
        return $this->belongsTo(SourceActivation::class, 'activation_id', 'id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id', 'id');
    }

    public function knowledgeSourceBinding(): HasOne
    {
        return $this->hasOne(KnowledgeSourceBinding::class, 'extracted_source_id', 'id');
    }
}
