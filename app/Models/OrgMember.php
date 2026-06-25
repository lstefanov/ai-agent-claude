<?php

namespace App\Models;

use App\Support\ModelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Стабилната идентичност на член на организацията — „служителят за цял живот".
 * Преживява org версиите: тук висят персона/чат/памет, нивото (default_star_tier)
 * и — за асистентите — задачите. directors/assistants са плейсмънт редове, които
 * сочат този член в конкретна версия.
 */
class OrgMember extends Model
{
    protected $fillable = [
        'company_id', 'kind', 'key', 'display_name', 'status',
        'retired_at', 'default_star_tier', 'avatar_url',
    ];

    protected $casts = [
        'retired_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Персоната на члена (една на член). */
    public function persona(): HasOne
    {
        return $this->hasOne(Persona::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(MemberChat::class);
    }

    /** Задачите (само за kind=assistant) — висят на стабилния член. */
    public function tasks(): HasMany
    {
        return $this->hasMany(AssistantTask::class);
    }

    /** Директорските плейсмънти на члена през всички версии. */
    public function directorPlacements(): HasMany
    {
        return $this->hasMany(Director::class);
    }

    /** Асистентските плейсмънти на члена през всички версии. */
    public function assistantPlacements(): HasMany
    {
        return $this->hasMany(Assistant::class);
    }

    /** Всички плейсмънти (директор + асистент) на члена през версиите. */
    public function placements(): Collection
    {
        return $this->directorPlacements->merge($this->assistantPlacements);
    }

    /** Плейсмънтът му в активната версия на компанията (или null). */
    public function currentPlacement(): Director|Assistant|null
    {
        $activeVersionId = $this->company->active_org_version_id;
        if (! $activeVersionId) {
            return null;
        }

        if ($this->kind === 'director') {
            return Director::where('org_version_id', $activeVersionId)
                ->where('org_member_id', $this->id)->first();
        }

        if ($this->kind === 'assistant') {
            return Assistant::where('org_version_id', $activeVersionId)
                ->where('org_member_id', $this->id)->first();
        }

        return null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Детерминистичен код-алокатор на стабилен `key` за НОВ член. Единственото място,
     * което ражда ключове — НИКОГА LLM. slug на името + суфикс за уникалност по (company, key).
     */
    public static function allocateKey(Company $company, string $kind, string $displayName): string
    {
        // Управителят е винаги един на компания → фиксиран ключ "manager".
        $base = $kind === 'manager' ? 'manager' : Str::slug($displayName);
        $base = $base !== '' ? Str::limit($base, 40, '') : $kind;

        $key = $base;
        $suffix = 2;
        // Уникалност по (company, key): -2, -3, … докато се освободи.
        while (static::where('company_id', $company->id)->where('key', $key)->exists()) {
            $key = $base.'-'.$suffix;
            $suffix++;
        }

        return $key;
    }

    /** Нивото/рангът на члена (от колоната default_star_tier). */
    public function defaultStarTier(): ModelLevel
    {
        return ModelLevel::from($this->default_star_tier);
    }

    /**
     * Повишение/понижение на члена: записва новото ниво (стабилен ранг) и логва
     * org_event (mandate_change). Промяната веднага мени effective tier на всичките
     * му задачи БЕЗ явен override (рецена на кредитите).
     */
    public function setDefaultStarTier(ModelLevel $tier): void
    {
        $this->update(['default_star_tier' => $tier->value]);

        // Реценообразуване (§6.1): задачите БЕЗ явен override → tier_stale (lazy re-pin при
        // следващото пускане). Задача с override остава фиксирана. Без изненадваща цена сега.
        AssistantTask::where('org_member_id', $this->id)->whereNull('star_tier')->update(['tier_stale' => true]);

        $this->company->orgEvents()->create([
            'type' => 'mandate_change',
            'org_member_id' => $this->id,
            'summary' => "Нивото на {$this->display_name} → {$tier->label()}",
            'actor' => 'human',
        ]);
    }

    /**
     * Само за директор-член (и САМО през UI „повиши целия отдел", не тихо): сетва
     * default_star_tier на асистентите си в активната версия → каскадира към задачите им.
     */
    public function applyTierToDepartment(ModelLevel $tier): void
    {
        $placement = $this->currentPlacement();
        if (! $placement instanceof Director) {
            return;
        }

        foreach ($placement->assistants as $assistant) {
            $assistant->orgMember?->setDefaultStarTier($tier);
        }
    }
}
