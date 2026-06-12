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

    /** Безопасно логване — одитът никога не чупи ingest/run. */
    public static function log(
        int $companyId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $title,
        ?string $snippet = null,
        ?string $source = null,
        array $meta = [],
    ): void {
        try {
            self::create([
                'company_id' => $companyId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'title' => mb_substr($title, 0, 300),
                'snippet' => $snippet !== null ? mb_substr($snippet, 0, 16000) : null,
                'source' => $source !== null ? mb_substr($source, 0, 300) : null,
                'meta' => $meta !== [] ? $meta : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
