<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTemplate extends Model
{
    protected $fillable = [
        'company_id', 'name', 'description', 'icon', 'type',
        'role', 'system_prompt', 'prompt_template', 'model',
        'capabilities', 'strengths', 'limitations',
        'input_description', 'output_description',
        'is_verifier', 'qa_threshold', 'config', 'sort_order',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'config'       => 'array',
        'is_verifier'  => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSystem(): bool
    {
        return $this->company_id === null;
    }
}
