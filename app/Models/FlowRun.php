<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRun extends Model
{
    protected $fillable = ['flow_id', 'status', 'triggered_by', 'context', 'final_output', 'final_output_model', 'graph_snapshot', 'started_at', 'completed_at'];

    protected $casts = [
        'context'        => 'array',
        'graph_snapshot' => 'array',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function nodeRuns(): HasMany
    {
        return $this->hasMany(NodeRun::class);
    }
}
