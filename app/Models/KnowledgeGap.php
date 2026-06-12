<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grounding search that found no good coverage in the company's knowledge
 * base — the actionable "качи документ за това" signal in the KB UI. Когато
 * по-късно пристигне покриващо знание (нов ресурс, факт от run), пропускът
 * се маркира resolved ("готов") с резолюция кой го е запълнил.
 */
class KnowledgeGap extends Model
{
    protected $fillable = [
        'company_id', 'flow_run_id', 'node_key', 'query', 'best_score',
        'embedding', 'embedding_provider', 'status', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'best_score' => 'float',
        'embedding' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
