<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only журнал зад резервациите (reserve/settle/refund/topup/grant редове).
 * Само created_at — записите никога не се променят.
 */
class CreditLedgerEntry extends Model
{
    protected $table = 'credit_ledger';

    // Append-only: само created_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'credit_wallet_id', 'company_id', 'reservation_id', 'type', 'origin',
        'idempotency_key', 'direction', 'amount', 'wallet_balance_after', 'reason',
        'flow_run_id', 'node_run_id', 'cost_usd', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'cost_usd' => 'decimal:6',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'credit_wallet_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class, 'reservation_id');
    }
}
