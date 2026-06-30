<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Едно изискване за знание на задача (§2-етапни задачи). sourceability + how_to_provide
 * идват от планера; status/best_score/evidence_sources се поставят от кода
 * (KnowledgeRequirementService::evaluate) — планерът предлага, кодът гарантира.
 */
class AssistantTaskKnowledgeRequirement extends Model
{
    protected $fillable = [
        'assistant_task_id', 'key', 'label', 'query', 'sourceability',
        'status', 'best_score', 'evidence_sources', 'how_to_provide',
        'acknowledged', 'resolved_at',
    ];

    protected $casts = [
        'best_score' => 'float',
        'evidence_sources' => 'array',
        'acknowledged' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AssistantTask::class, 'assistant_task_id');
    }

    /** Покрито знание = изпълнено изискване. */
    public function isCovered(): bool
    {
        return $this->status === 'covered';
    }
}
