<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Flow extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'topic', 'status', 'schedule_cron', 'last_run_at', 'is_archived', 'archived_at', 'webhook_secret', 'graph_layout', 'plan_intent', 'settings'];

    protected $casts = [
        'last_run_at' => 'datetime',
        'archived_at' => 'datetime',
        'is_archived' => 'boolean',
        'graph_layout' => 'array',
        'plan_intent' => 'array',
        'settings' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function assistantMessages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(FlowVersion::class)->where('is_active', true);
    }
}
