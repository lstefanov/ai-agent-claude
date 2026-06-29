<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Плейсмънт ред = ролята/мястото на члена В ТАЗИ версия. Персоната и нивото се
 * четат през orgMember (стабилната идентичност), не се дублират тук.
 */
class Director extends Model
{
    protected $fillable = [
        'org_version_id', 'org_member_id', 'title', 'domain',
        'mandate', 'priorities', 'kpi', 'position', 'status', 'last_proposed_at',
    ];

    protected $casts = [
        'priorities' => 'array',
        'kpi' => 'array',
        'position' => 'array',
        'last_proposed_at' => 'datetime',
    ];

    public function orgVersion(): BelongsTo
    {
        return $this->belongsTo(OrgVersion::class);
    }

    public function orgMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class);
    }

    public function assistants(): HasMany
    {
        return $this->hasMany(Assistant::class);
    }
}
