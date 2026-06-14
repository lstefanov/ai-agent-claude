<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Flow extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'topic', 'status', 'schedule_cron', 'last_run_at', 'is_archived', 'archived_at', 'webhook_secret', 'settings'];

    protected $casts = [
        'last_run_at' => 'datetime',
        'archived_at' => 'datetime',
        'is_archived' => 'boolean',
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

    public function flowRuns(): HasMany
    {
        return $this->hasMany(FlowRun::class);
    }

    public function nodeRuns(): HasManyThrough
    {
        return $this->hasManyThrough(NodeRun::class, FlowRun::class);
    }

    public function assistantMessages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(FlowMemory::class);
    }

    public function evalCases(): HasMany
    {
        return $this->hasMany(FlowEvalCase::class);
    }

    public function evalRuns(): HasMany
    {
        return $this->hasMany(FlowEvalRun::class);
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(FlowVersion::class)->where('is_active', true);
    }
}
