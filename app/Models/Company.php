<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = ['name', 'description', 'industry', 'language', 'settings', 'website_url', 'active_org_version_id'];

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

    // --- AI Организация (org слой) ---

    /** Всички org членове на фирмата (стабилната идентичност). */
    public function members(): HasMany
    {
        return $this->hasMany(OrgMember::class);
    }

    /** Управителят (един на фирма). */
    public function manager(): HasOne
    {
        return $this->hasOne(OrgMember::class)->where('kind', 'manager');
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function orgVersions(): HasMany
    {
        return $this->hasMany(OrgVersion::class);
    }

    /** Активната (одобрена) org версия на фирмата. */
    public function activeOrgVersion(): BelongsTo
    {
        return $this->belongsTo(OrgVersion::class, 'active_org_version_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function creditWallet(): HasOne
    {
        return $this->hasOne(CreditWallet::class);
    }

    public function orgEvents(): HasMany
    {
        return $this->hasMany(OrgEvent::class);
    }
}
