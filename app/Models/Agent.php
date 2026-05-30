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
        'output_language', 'output_tone', 'output_style', 'output_format', 'output_role',
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

    private const TYPE_ROLE_MAP = [
        'researcher'  => 'hidden',
        'analyzer'    => 'hidden',
        'scraper'     => 'hidden',
        'content_bg'  => 'body',
        'content_en'  => 'body',
        'writer'      => 'body',
        'translator'  => 'body',
        'hashtag'     => 'appendix',
        'hashtags'    => 'appendix',
        'tags'        => 'appendix',
        'seo'         => 'appendix',
        'qa_verifier' => 'quality',
        'verifier'    => 'quality',
        'email'       => 'appendix',
    ];

    public function effectiveOutputRole(): string
    {
        if ($this->output_role) return $this->output_role;
        if ($this->is_verifier) return 'quality';
        return self::TYPE_ROLE_MAP[$this->type] ?? 'body';
    }
}
