<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $fillable = [
        'flow_id', 'name', 'type', 'role', 'capabilities', 'strengths', 'limitations',
        'input_description', 'output_description', 'prompt_template', 'system_prompt',
        'model', 'model_reason', 'order', 'is_verifier', 'qa_threshold', 'depends_on', 'config', 'is_active',
        'output_language', 'output_tone', 'output_style', 'output_format',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'depends_on'   => 'array',
        'config'       => 'array',
        'is_verifier'  => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
