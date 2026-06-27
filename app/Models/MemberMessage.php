<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberMessage extends Model
{
    protected $fillable = [
        'member_chat_id', 'role', 'content', 'payload',
        'status', 'error', 'cost_usd',
    ];

    protected $casts = [
        'payload' => 'array',          // предложено действие → за Кутията
        'cost_usd' => 'decimal:6',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(MemberChat::class, 'member_chat_id');
    }
}
