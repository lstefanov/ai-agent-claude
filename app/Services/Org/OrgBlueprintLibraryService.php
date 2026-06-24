<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Models\OrgBlueprint;
use App\Models\OrgVersion;
use App\Services\EmbeddingService;
use Illuminate\Support\Collection;

/**
 * Паметта на Управителя (като PlanLibraryService): org-структури по вертикала →
 * few-shot прайъри за дизайна; учеща се (одобрена версия → blueprint; първа успешна
 * задача → proven).
 */
class OrgBlueprintLibraryService
{
    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * Топ-k blueprints за дизайна. Ако blueprint-ите имат embeddings → cosine спрямо
     * бизнес профила; иначе fallback по вертикал (seed blueprints нямат embedding).
     *
     * @return Collection<int, OrgBlueprint>
     */
    public function bestMatch(Company $company, int $k = 3): Collection
    {
        $vertical = $this->verticalFor($company);

        // Предпочитай proven, после по вертикал — стабилен прайър без embeddings.
        $byVertical = OrgBlueprint::where('vertical', $vertical)
            ->orderByDesc('proven')->orderByDesc('id')->limit($k)->get();

        if ($byVertical->isNotEmpty()) {
            return $byVertical;
        }

        return OrgBlueprint::orderByDesc('proven')->orderByDesc('id')->limit($k)->get();
    }

    /** Одобрена версия → blueprint (учеща библиотека). */
    public function snapshot(OrgVersion $version): OrgBlueprint
    {
        $company = $version->company;
        $structure = $this->structureFromVersion($version);

        return OrgBlueprint::create([
            'vertical' => $this->verticalFor($company),
            'name' => $company->name.' v'.$version->version,
            'structure' => $structure,
            'proven' => false,
            'source_company_id' => $company->id,
        ]);
    }

    /** След първа успешна задача на версията → proven (огледало на plan_library). */
    public function markProven(OrgVersion $version): void
    {
        OrgBlueprint::where('source_company_id', $version->company_id)
            ->where('name', $version->company->name.' v'.$version->version)
            ->update(['proven' => true]);
    }

    /** Идемпотентно: snapshot на версията (ако липсва) + proven=true. Викано от успешен run (§7.3). */
    public function learnFromVersion(OrgVersion $version): void
    {
        $name = $version->company->name.' v'.$version->version;
        $blueprint = OrgBlueprint::where('source_company_id', $version->company_id)->where('name', $name)->first()
            ?? $this->snapshot($version);

        $blueprint->update(['proven' => true]);
    }

    /** Бранш → seed вертикал. */
    public function verticalFor(Company $company): string
    {
        $industry = mb_strtolower((string) $company->industry);

        return match (true) {
            str_contains($industry, 'фитнес') || str_contains($industry, 'спорт') || str_contains($industry, 'fitness') => 'fitness',
            str_contains($industry, 'ресторант') || str_contains($industry, 'кафе') || str_contains($industry, 'restaurant') || str_contains($industry, 'food') => 'restaurant',
            default => 'services',
        };
    }

    /** Извежда blueprint-структура от плейсмънтите на дадена версия. */
    private function structureFromVersion(OrgVersion $version): array
    {
        $directors = $version->directors()->with('orgMember')->get()->map(fn ($d) => [
            'key' => $d->orgMember?->key,
            'title' => $d->title,
            'domain' => $d->domain,
            'default_star_tier' => $d->orgMember?->default_star_tier,
            'mandate' => $d->mandate,
        ])->all();

        $assistants = $version->assistants()->with(['orgMember', 'director.orgMember'])->get()->map(fn ($a) => [
            'key' => $a->orgMember?->key,
            'title' => $a->title,
            'director' => $a->director?->orgMember?->key,
            'default_star_tier' => $a->orgMember?->default_star_tier,
            'mandate' => $a->mandate,
        ])->all();

        return ['directors' => $directors, 'assistants' => $assistants, 'tasks' => []];
    }
}
