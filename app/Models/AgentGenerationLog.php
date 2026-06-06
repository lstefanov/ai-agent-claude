<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentGenerationLog extends Model
{
    protected $fillable = [
        'flow_id', 'company_id', 'token', 'provider', 'model',
        'system_prompt', 'user_message', 'options', 'raw_response',
        'parsed_count', 'status', 'error', 'duration_ms',
        'prompt_tokens', 'completion_tokens', 'cost_usd',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
