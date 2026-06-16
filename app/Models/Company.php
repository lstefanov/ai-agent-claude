<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Служебният „owner" потребител на фирмата (за клиентския вход). */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'owner');
    }

    public function connectors(): HasMany
    {
        return $this->hasMany(CompanyConnector::class);
    }

    public function knowledgeFolders(): HasMany
    {
        return $this->hasMany(KnowledgeFolder::class);
    }

    public function knowledgeResources(): HasMany
    {
        return $this->hasMany(KnowledgeResource::class);
    }

    public function knowledgePages(): HasMany
    {
        return $this->hasMany(KnowledgePage::class);
    }

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function knowledgeFacts(): HasMany
    {
        return $this->hasMany(KnowledgeFact::class);
    }

    public function knowledgeEvents(): HasMany
    {
        return $this->hasMany(KnowledgeEvent::class);
    }
}
