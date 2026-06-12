<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One embeddable excerpt of a knowledge document. company_id is denormalized
 * so similarity search scans a single table per company.
 */
class KnowledgeChunk extends Model
{
    protected $fillable = [
        'knowledge_document_id', 'company_id', 'seq', 'content',
        'embedding', 'embedding_provider', 'meta',
    ];

    protected $casts = [
        'embedding' => 'array',
        'meta' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
