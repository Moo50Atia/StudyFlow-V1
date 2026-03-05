<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicViewModel extends Model
{
    /** @use HasFactory<\Database\Factories\DynamicViewModelFactory> */
    use HasFactory;

    protected $fillable = [
        "section_id",
        "blueprint_json",
        "html_content",
        "version",
        "is_generated",
    ];

    protected $casts = [
        "blueprint_json" => "array",
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
