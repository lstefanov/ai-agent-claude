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
        'is_verifier', 'qa_threshold', 'config', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'config' => 'array',
        'is_verifier' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isSystem(): bool
    {
        return $this->company_id === null;
    }
}
