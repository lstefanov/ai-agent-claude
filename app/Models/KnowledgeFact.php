<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Един факт от натрупващия се "профил" на фирмата: какво предлага, на какви
 * цени, къде, как се свързваш с нея. Фактите идват от ресурси/страници при
 * ingest, от изходите на агентите след всеки успешен run и от чата — така
 * знанието е винаги up to date. Ново знание за същото нещо supersede-ва
 * старото (не го трие), а одит-историята показва промяната.
 */
class KnowledgeFact extends Model
{
    public const CATEGORIES = [
        'services', 'prices', 'contacts', 'locations', 'about',
        'team', 'competitors', 'faq', 'other',
    ];

    protected $fillable = [
        'company_id', 'category', 'location', 'name', 'value', 'source_type',
        'source_id', 'flow_run_id', 'confidence', 'status', 'embedding',
        'embedding_provider',
    ];

    protected $casts = [
        'embedding' => 'array',
        'confidence' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
