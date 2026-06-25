<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Един конфликт в знанието: група активни факти за едно и също нещо (същата
 * категория + локация), но с различни стойности. Разрешава се ръчно от таб
 * „Конфликти" — избира се победителят, другите стават superseded.
 */
class KnowledgeConflict extends Model
{
    protected $fillable = [
        'company_id', 'category', 'location', 'subject', 'fact_ids',
        'status', 'resolved_fact_id', 'resolved_at', 'signature',
    ];

    protected $casts = [
        'fact_ids' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
