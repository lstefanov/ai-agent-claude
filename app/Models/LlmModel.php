<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmModel extends Model
{
    protected $table = 'llm_models';

    protected $fillable = [
        'ollama_tag', 'display_name', 'category', 'description',
        'strengths', 'ram_required_gb', 'size_mb', 'is_available', 'is_enabled', 'is_default_for',
        'pull_status', 'pull_progress', 'pull_error',
    ];

    protected $casts = [
        'strengths' => 'array',
        'is_default_for' => 'array',
        'is_available' => 'boolean',
        'is_enabled' => 'boolean',
    ];
}
