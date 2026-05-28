<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmModel extends Model
{
    protected $table = 'llm_models';

    protected $fillable = [
        'ollama_tag', 'display_name', 'category', 'description',
        'strengths', 'ram_required_gb', 'is_available', 'is_default_for',
    ];

    protected $casts = [
        'strengths'      => 'array',
        'is_default_for' => 'array',
        'is_available'   => 'boolean',
    ];
}
