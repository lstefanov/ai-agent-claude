<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Едно съобщение в разговора на създателя. `payload` носи структурирания
 * въпрос (assistant) или избрания отговор от формата (user).
 */
class FlowDraftMessage extends Model
{
    protected $fillable = [
        'flow_draft_id', 'role', 'content', 'payload', 'status', 'error', 'cost_usd',
    ];

    protected $casts = [
        'payload' => 'array',
        'cost_usd' => 'float',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(FlowDraft::class, 'flow_draft_id');
    }
}
