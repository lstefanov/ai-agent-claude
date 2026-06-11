<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable fact the Builder Copilot was asked to remember ("запомни, че...").
 * Flow-scoped notes apply to one flow; company-scoped (flow_id = null) apply
 * to every flow of the company. Injected into the assistant's system prompt.
 */
class AssistantNote extends Model
{
    protected $fillable = ['company_id', 'flow_id', 'note'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
