<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowEvalRun extends Model
{
    protected $fillable = ['flow_id', 'flow_version_id', 'flow_run_id', 'eval_case_id', 'model_level',
        'session_token', 'status', 'score', 'scores_detail', 'cost_usd', 'duration_ms', 'final_output', 'judge_log', 'error'];

    protected $casts = [
        'scores_detail' => 'array',
        'judge_log' => 'array',
        'score' => 'float',
        'cost_usd' => 'float',
        'duration_ms' => 'integer',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function evalCase(): BelongsTo
    {
        return $this->belongsTo(FlowEvalCase::class, 'eval_case_id');
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }
}
