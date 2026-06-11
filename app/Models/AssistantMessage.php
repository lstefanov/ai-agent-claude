<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a Builder Copilot conversation (chat panel in the flow
 * builder). Threads are grouped by `session` uuid per flow; the assistant's
 * graph proposals travel in `ops` (applied on the Drawflow canvas client-side
 * — saving the graph remains the approval step).
 */
class AssistantMessage extends Model
{
    protected $fillable = [
        'flow_id', 'session', 'role', 'content', 'ops', 'ui', 'status', 'error', 'cost_usd',
    ];

    protected $casts = [
        'ops' => 'array',
        'ui' => 'array',
        'cost_usd' => 'float',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
