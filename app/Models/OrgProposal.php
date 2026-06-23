<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MUTABLE решението зад DecisionBox (§A7) — org_events остава отделният append-only
 * одит. base_org_version_id пази срещу коя активна версия е изготвено (optimistic concurrency).
 */
class OrgProposal extends Model
{
    protected $fillable = [
        'company_id', 'type', 'payload', 'status',
        'base_org_version_id', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function baseOrgVersion(): BelongsTo
    {
        return $this->belongsTo(OrgVersion::class, 'base_org_version_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param  Builder<OrgProposal>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
