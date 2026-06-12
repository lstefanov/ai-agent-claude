<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grounding search that found no good coverage in the company's knowledge
 * base — the actionable "качи документ за това" signal in the KB UI.
 */
class KnowledgeGap extends Model
{
    protected $fillable = ['company_id', 'flow_run_id', 'node_key', 'query', 'best_score'];

    protected $casts = ['best_score' => 'float'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }
}
