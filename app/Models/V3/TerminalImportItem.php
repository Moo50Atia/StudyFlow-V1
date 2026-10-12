<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerminalImportItem extends Model
{
    protected $table = 'v3_terminal_import_items';

    protected $primaryKey = ['import_id', 'pdf_ordinal'];

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pdf_ordinal' => 'integer',
            'committed_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(TerminalImport::class, 'import_id');
    }
}
