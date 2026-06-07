<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid LLM API call (OpenAI / Anthropic) — see the migration for intent.
 * Written by App\Support\LlmRequestRecorder; read by the admin Costs page.
 */
class LlmRequest extends Model
{
    protected $fillable = [
        'provider', 'model', 'kind', 'purpose', 'session_id',
        'company_id', 'flow_id', 'flow_run_id', 'node_run_id', 'agent_name', 'agent_type',
        'system_prompt', 'user_message', 'response_text', 'options',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd', 'duration_ms',
        'status', 'error',
    ];

    protected $casts = [
        'options' => 'array',
        'cost_usd' => 'decimal:6',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function nodeRun(): BelongsTo
    {
        return $this->belongsTo(NodeRun::class);
    }
}
