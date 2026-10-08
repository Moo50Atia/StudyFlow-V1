<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotebookFrameworkVersion extends Model
{
    protected $table = 'v3_notebook_framework_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'body_bytes' => 'integer',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \InvalidArgumentException('NotebookFrameworkVersion records are strictly immutable.');
        });
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
