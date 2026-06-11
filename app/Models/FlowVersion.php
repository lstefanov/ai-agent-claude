<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowVersion extends Model
{
    protected $fillable = ['flow_id', 'name', 'is_active', 'agents', 'graph_layout', 'plan_intent', 'generator', 'model_level', 'cost_usd', 'duration_ms'];

    protected $casts = [
        'is_active' => 'boolean',
        'agents' => 'array',
        'graph_layout' => 'array',
        'plan_intent' => 'array',
        'generator' => 'array',
        'cost_usd' => 'float',
        'duration_ms' => 'integer',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(FlowEdge::class);
    }

    public function flowRuns(): HasMany
    {
        return $this->hasMany(FlowRun::class);
    }

    /** Human-readable "with what was this generated" for lists/badges. */
    public function generatorLabel(): string
    {
        return (string) ($this->generator['label'] ?? '—');
    }
}
