<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered fact about a flow's past executions:
 *  - kind 'output' — a digest (+ embedding) of content a run produced, used to
 *    steer and gate future runs away from duplicates;
 *  - kind 'lesson' — a short per-node note distilled from QA/replan events,
 *    injected into that node's prompt on subsequent runs.
 */
class FlowMemory extends Model
{
    protected $fillable = [
        'flow_id', 'flow_run_id', 'node_key', 'kind', 'title', 'summary',
        'embedding', 'embedding_provider', 'meta',
    ];

    protected $casts = [
        'embedding' => 'array',
        'meta' => 'array',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }
}
