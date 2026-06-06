<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanLibraryEntry extends Model
{
    protected $table = 'plan_library';

    protected $fillable = [
        'flow_id', 'company_id', 'intent', 'agents', 'embedding',
        'deliverable', 'language', 'complexity', 'information_sources',
        'needs_image', 'needs_hashtags', 'competitor_focus', 'improvement_suggestions',
        'status', 'runs_count', 'avg_qa_score', 'last_run_at',
    ];

    protected $casts = [
        'intent' => 'array',
        'agents' => 'array',
        'embedding' => 'array',
        'information_sources' => 'array',
        'needs_image' => 'boolean',
        'needs_hashtags' => 'boolean',
        'competitor_focus' => 'boolean',
        'improvement_suggestions' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
