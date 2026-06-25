<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Mutable състоянието на ЕДНА билинг-операция (§A1). CreditMeterService го движи
 * (reserve → settle/refund/expired); credit_ledger е append-only журналът зад нея.
 */
class CreditReservation extends Model
{
    protected $fillable = [
        'company_id', 'context_type', 'subject_type', 'subject_id',
        'estimated_credits', 'spent_credits', 'status', 'idempotency_key',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class, 'reservation_id');
    }

    /** Полиморфният субект: org_member / assistant_task / flow_run според контекста. */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    /** Резервацията е още отворена (не е settle-ната/refund-ната). */
    public function isOpen(): bool
    {
        return $this->status === 'reserved';
    }

    /** Оставащи (резервирани, но непохарчени) кредити. */
    public function remaining(): int
    {
        return (int) $this->estimated_credits - (int) $this->spent_credits;
    }
}
