<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditWallet extends Model
{
    protected $fillable = [
        'company_id', 'balance', 'included_this_period',
        'overage_used', 'period_start',
    ];

    protected $casts = [
        'period_start' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    /** Има поне $n кредита наличен баланс. */
    public function hasCredits(int $n): bool
    {
        return $this->balance >= $n;
    }
}
