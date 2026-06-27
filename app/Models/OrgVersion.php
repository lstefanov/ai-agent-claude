<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgVersion extends Model
{
    protected $fillable = [
        'company_id', 'version', 'status', 'summary',
        'blueprint_key', 'approved_at', 'created_by',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Плейсмънт редовете на директорите в тази версия. */
    public function directors(): HasMany
    {
        return $this->hasMany(Director::class);
    }

    /** Плейсмънт редовете на асистентите в тази версия. */
    public function assistants(): HasMany
    {
        return $this->hasMany(Assistant::class);
    }

    /** @param  Builder<OrgVersion>  $query */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }
}
