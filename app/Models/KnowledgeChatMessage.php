<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Едно съобщение в чата "Тествай знанията" (страницата на базата знания).
 * Същата механика като AssistantMessage в билдъра: session uuid групира
 * разговора, асистентските редове минават pending → completed през опашката.
 * sources държи цитираните източници [{type, id, title, url, score}].
 */
class KnowledgeChatMessage extends Model
{
    protected $fillable = [
        'company_id', 'session', 'role', 'content', 'sources', 'status', 'error', 'cost_usd',
    ];

    protected $casts = [
        'sources' => 'array',
        'cost_usd' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
