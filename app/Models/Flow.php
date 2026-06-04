<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'status', 'schedule_cron', 'last_run_at', 'is_archived', 'archived_at', 'webhook_secret', 'graph_layout'];

    protected $casts = [
        'last_run_at'  => 'datetime',
        'archived_at'  => 'datetime',
        'is_archived'  => 'boolean',
        'graph_layout' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
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
}
