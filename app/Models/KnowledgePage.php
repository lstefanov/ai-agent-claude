<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Една обходена страница на url ресурс: URL + title + meta + content_hash на
 * суровия markdown + LLM-синтезиран digest. Непроменен content_hash при
 * повторно обхождане = digest-ът и чанковете се преизползват без LLM разход.
 */
class KnowledgePage extends Model
{
    protected $fillable = [
        'knowledge_resource_id', 'company_id', 'url', 'url_hash', 'title',
        'meta_description', 'content_hash', 'digest', 'status', 'error',
        'parsed_at', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'parsed_at' => 'datetime',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeResource::class, 'knowledge_resource_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
