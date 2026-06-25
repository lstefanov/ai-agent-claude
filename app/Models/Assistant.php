<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Плейсмънт ред на асистент в дадена версия. Задачите/персоната се четат през orgMember.
 */
class Assistant extends Model
{
    protected $fillable = [
        'org_version_id', 'org_member_id', 'director_id',
        'title', 'mandate', 'kpi', 'position', 'status',
    ];

    protected $casts = [
        'kpi' => 'array',
        'position' => 'array',
    ];

    public function orgVersion(): BelongsTo
    {
        return $this->belongsTo(OrgVersion::class);
    }

    public function orgMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class);
    }

    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }
}
