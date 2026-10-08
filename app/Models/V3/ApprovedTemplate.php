<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;

class ApprovedTemplate extends Model
{
    protected $table = 'v3_approved_templates';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'kind',
        'body',
        'checksum',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \LogicException('Approved templates are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('Approved templates are immutable and cannot be deleted.');
        });
    }
}
