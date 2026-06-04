<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeRun extends Model
{
    protected $fillable = [
        'flow_run_id', 'flow_node_id', 'node_key', 'status', 'input', 'output', 'raw_output',
        'quality_metrics', 'params_snapshot', 'model_used', 'tokens_used', 'duration_ms', 'error',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'quality_metrics' => 'array',
        'params_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }
}
