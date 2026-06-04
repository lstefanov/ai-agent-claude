<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowNode extends Model
{
    protected $fillable = [
        'flow_id', 'node_key', 'name', 'role', 'type', 'icon', 'prompt_template', 'system_prompt',
        'model', 'config', 'output_language', 'output_tone', 'output_style', 'output_format',
        'output_role', 'pos_x', 'pos_y', 'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'pos_x' => 'integer',
        'pos_y' => 'integer',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function nodeRuns(): HasMany
    {
        return $this->hasMany(NodeRun::class);
    }

    /**
     * Resolve the output role from an explicit column, else fall back to the
     * type's default in config/agent_types.php (mirrors Agent::effectiveOutputRole).
     */
    public function effectiveOutputRole(): string
    {
        if ($this->output_role) {
            return $this->output_role;
        }

        return config("agent_types.{$this->type}.output_role", 'body');
    }
}
