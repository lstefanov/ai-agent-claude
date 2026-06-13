<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Един РЕСУРС в базата знания на фирмата (NotebookLM стил): url (сайт/страница,
 * обхожда се BFS и ражда KnowledgePage-и), upload (документ), image (снимка →
 * OCR), note (бележка, създавана в UI) или chat (одобрен с 👍 интернет-отговор
 * от чата "Тествай знанията", промотиран в знание). Изтриването на ресурс
 * "забравя" всичко произлязло от него (страници/чанкове/факти — cascade + изрично).
 */
class KnowledgeResource extends Model
{
    public const TYPES = ['url', 'upload', 'image', 'note', 'chat'];

    protected $fillable = [
        'company_id', 'folder_id', 'type', 'title', 'original_name', 'mime',
        'size_bytes', 'storage_path', 'content', 'url', 'url_hash', 'status',
        'error', 'chunk_count', 'cost_usd', 'meta', 'ingested_at',
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

    public function pages(): HasMany
    {
        return $this->hasMany(KnowledgePage::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }
}
