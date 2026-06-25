<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Runtime DTO: NodeExecutorService bridges each FlowNode into a transient
 * Agent instance so the concrete agent classes (BaseAgent subclasses) have a
 * uniform contract. Agents are not persisted — pipelines live in flow_nodes.
 */
class Agent extends Model
{
    protected $fillable = [
        'flow_id', 'name', 'icon', 'type', 'role', 'capabilities', 'strengths', 'limitations',
        'input_description', 'output_description', 'prompt_template', 'system_prompt',
        'model', 'model_reason', 'order', 'is_verifier', 'qa_threshold', 'depends_on', 'config', 'is_active',
        'output_language', 'output_tone', 'output_style', 'output_format', 'output_role',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'depends_on' => 'array',
        'config' => 'array',
        'is_verifier' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
