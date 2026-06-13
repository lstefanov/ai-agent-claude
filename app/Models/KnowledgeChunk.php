<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One embeddable excerpt of a knowledge resource (or of a single crawled
 * page for url resources). company_id is denormalized so similarity search
 * scans a single table per company; content носи и FULLTEXT индекс за
 * keyword половината на hybrid търсенето.
 */
class KnowledgeChunk extends Model
{
    protected $fillable = [
        'company_id', 'knowledge_resource_id', 'knowledge_page_id', 'seq',
        'content', 'embedding', 'embedding_provider', 'meta',
    ];

    protected $casts = [
        'embedding' => 'array',
        'meta' => 'array',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeResource::class, 'knowledge_resource_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(KnowledgePage::class, 'knowledge_page_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
