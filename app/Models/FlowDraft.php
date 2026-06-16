<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Една сесия на разговорния създател: интервюто + сглобяващото се описание.
 * При „Готово, Генерирай" се закача към създадения Flow (flow_id).
 */
class FlowDraft extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'session', 'status', 'title', 'description', 'answers', 'script', 'flow_id',
    ];

    protected $casts = [
        'answers' => 'array',
        'script' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FlowDraftMessage::class);
    }
}
