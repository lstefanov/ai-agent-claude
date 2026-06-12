<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одит-историята на знанията: кое знание (snippet пази синтезираното
 * съдържание към момента на събитието — оцелява изтриване на subject-а),
 * откъде е дошло (source — човешки текст) и кога. Append-only.
 */
class KnowledgeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'action', 'subject_type', 'subject_id', 'title',
        'snippet', 'source', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
