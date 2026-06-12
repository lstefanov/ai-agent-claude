<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One source in a company's knowledge base. source_type doubles as the
 * collection: 'upload'|'site'|'url' ground agents in company facts, while
 * 'run' holds distilled results of past flow runs (searchable on demand,
 * never auto-injected into prompts).
 */
class KnowledgeDocument extends Model
{
    /** Collections whose content counts as company TRUTH (prompt grounding). */
    public const GROUNDING_TYPES = ['upload', 'site', 'url'];

    protected $fillable = [
        'company_id', 'folder_id', 'source_type', 'title', 'original_name',
        'mime', 'size_bytes', 'storage_path', 'source_url', 'source_url_hash',
        'flow_run_id', 'content_hash', 'status', 'error', 'chunk_count',
        'cost_usd', 'meta', 'ingested_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'ingested_at' => 'datetime',
        'cost_usd' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFolder::class, 'folder_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    public function scopeGrounding(Builder $query): Builder
    {
        return $query->whereIn('source_type', self::GROUNDING_TYPES);
    }
}
