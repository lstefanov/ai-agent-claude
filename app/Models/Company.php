<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = ['name', 'description', 'industry', 'language', 'settings', 'website_url'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function knowledgeFolders(): HasMany
    {
        return $this->hasMany(KnowledgeFolder::class);
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
