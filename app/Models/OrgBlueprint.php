<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Библиотека от org шаблони по вертикала (директори + типични асистенти + задачи).
 */
class OrgBlueprint extends Model
{
    protected $fillable = [
        'vertical', 'name', 'structure', 'embedding', 'proven', 'source_company_id',
    ];

    protected $casts = [
        'structure' => 'array',
        'embedding' => 'array',
        'proven' => 'boolean',
    ];

    /** @param  Builder<OrgBlueprint>  $query */
    public function scopeProven(Builder $query): Builder
    {
        return $query->where('proven', true);
    }
}
