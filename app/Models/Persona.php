<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Персоната на член: био + RPG черти (traits) + изведени runtime knobs. Портретният
 * аватар се извежда от демографията — чисто козметичен (§5.3).
 */
class Persona extends Model
{
    protected $fillable = [
        'org_member_id', 'name', 'ethnicity', 'age', 'gender',
        'background', 'education', 'bio', 'traits', 'tone',
        'derived_knobs', 'archetype_key', 'avatar_url', 'avatar_prompt',
        'avatar_seed', 'avatar_status', 'avatar_meta',
    ];

    protected $casts = [
        'traits' => 'array',
        'derived_knobs' => 'array',
        'avatar_meta' => 'array',
    ];

    public function orgMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class);
    }

    /** Има готов портрет (status=ready + URL). */
    public function hasReadyAvatar(): bool
    {
        return $this->avatar_status === 'ready' && (bool) $this->avatar_url;
    }
}
