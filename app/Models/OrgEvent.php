<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only одит на организацията (само created_at, без updated_at).
 */
class OrgEvent extends Model
{
    // Append-only: управляваме само created_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'org_version_id', 'type', 'org_member_id',
        'subject_type', 'subject_id', 'summary', 'meta', 'actor',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function orgMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class);
    }
}
