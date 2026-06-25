<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder in a company's knowledge base. Folders nest (parent_id) and are
 * purely organizational — deleting one cascades to subfolders while its
 * resources drop to the root (FK nullOnDelete on knowledge_resources).
 */
class KnowledgeFolder extends Model
{
    protected $fillable = ['company_id', 'parent_id', 'name'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(KnowledgeResource::class, 'folder_id');
    }
}
