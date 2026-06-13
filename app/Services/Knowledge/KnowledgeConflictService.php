<?php

namespace App\Services\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeConflict;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use Illuminate\Support\Collection;

/**
 * Конфликти в знанието: ≥2 АКТИВНИ факта за едно и също нещо (същата категория
 * + локация, имена с достатъчно припокриване на СЪДЪРЖАТЕЛНИТЕ думи), но с
 * РАЗЛИЧНА стойност — съжителстват, защото dedup-ът не ги е слял. Изваждат се
 * за ръчно разрешаване.
 *
 * Защо lexical, а не embedding: embedding-ите НЕ разделят зоните — „…подмишници
 * мъже" срещу „…цяло тяло мъже" дават cosine 0.88 vs 0.876 (неразличими), значи
 * биха слепили различни зони в един конфликт. Сравнението на думите в имената
 * (без генеричните „цена/лазерна/епилация/…") разделя чисто темите.
 *
 * Никога не проваля ingest (try/catch, като KnowledgeGapService).
 */
class KnowledgeConflictService
{
    /**
     * Пълен скан: пресъздава ОТВОРЕНИТЕ конфликти за фирмата (ignored/resolved
     * остават недокоснати). Връща броя новооткрити open конфликти.
     */
    public function scan(Company $company): int
    {
        try {
            KnowledgeConflict::where('company_id', $company->id)->where('status', 'open')->delete();

            $facts = $company->knowledgeFacts()
                ->active()
                ->get(['id', 'category', 'location', 'name', 'value', 'created_at']);

            // Кофи по (category, location) — различна локация = отделна тема.
            $buckets = $facts->groupBy(fn (KnowledgeFact $f) => $f->category.'|'.($f->location ?? ''));

            $created = 0;
            foreach ($buckets as $bucket) {
                foreach ($this->clusterConflicts($bucket) as $group) {
                    if ($this->recordConflict($company, $group)) {
                        $created++;
                    }
                }
            }

            return $created;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** При ingest: новият факт може да противоречи на съществуващ активен факт. */
    public function detectForFact(Company $company, KnowledgeFact $new): void
    {
        try {
            $newTokens = $this->contentTokens($new->name);
            if ($newTokens === []) {
                return;
            }

            $neighbours = $company->knowledgeFacts()
                ->active()
                ->where('id', '!=', $new->id)
                ->where('category', $new->category)
                ->when(
                    $new->location !== null,
                    fn ($q) => $q->where('location', $new->location),
                    fn ($q) => $q->whereNull('location'),
                )
                ->get(['id', 'category', 'location', 'name', 'value', 'created_at']);

            $threshold = $this->overlapThreshold();
            $newIsPackage = $this->isPackage($new->name);
            $group = collect([$new]);

            foreach ($neighbours as $cand) {
                if ($this->sameValue($new->value, $cand->value)) {
                    continue;
                }
                // Пакетна цена ≠ единична — различни атрибути, не конфликт.
                if ($newIsPackage !== $this->isPackage($cand->name)) {
                    continue;
                }
                if ($this->jaccard($newTokens, $this->contentTokens($cand->name)) >= $threshold) {
                    $group->push($cand);
                }
            }

            if ($group->count() >= 2 && $this->distinctValues($group) >= 2) {
                $this->recordConflict($company, $group);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Разрешаване: победителят остава active, другите членове → superseded. */
    public function resolve(KnowledgeConflict $conflict, int $winnerFactId): void
    {
        $ids = array_map('intval', (array) $conflict->fact_ids);
        if (! in_array($winnerFactId, $ids, true)) {
            return;
        }

        $losers = array_values(array_diff($ids, [$winnerFactId]));
        if ($losers !== []) {
            KnowledgeFact::whereIn('id', $losers)->update(['status' => 'superseded']);
        }

        $winner = KnowledgeFact::find($winnerFactId);

        $conflict->update([
            'status' => 'resolved',
            'resolved_fact_id' => $winnerFactId,
            'resolved_at' => now(),
        ]);

        KnowledgeEvent::log(
            $conflict->company_id, 'updated', 'fact', $winnerFactId,
            'Разрешен конфликт: '.$conflict->subject,
            $winner ? 'Избрано: '.$winner->value : null,
            'ръчно разрешаване',
            ['conflict_id' => $conflict->id, 'superseded' => $losers],
        );
    }

    public function ignore(KnowledgeConflict $conflict): void
    {
        $conflict->update(['status' => 'ignored']);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function overlapThreshold(): float
    {
        return (float) config('services.knowledge.conflict_overlap', 0.7);
    }

    /**
     * Greedy single-link клъстериране в една кофа: seed притегля факти със
     * същата „тема" (content-token Jaccard ≥ праг). Група = конфликт само ако
     * има ≥2 различни стойности.
     *
     * @param  Collection<int, KnowledgeFact>  $bucket
     * @return array<int, Collection<int, KnowledgeFact>>
     */
    private function clusterConflicts(Collection $bucket): array
    {
        $threshold = $this->overlapThreshold();
        $tokensById = [];
        $packageById = [];
        foreach ($bucket as $f) {
            $tokensById[$f->id] = $this->contentTokens($f->name);
            $packageById[$f->id] = $this->isPackage($f->name);
        }

        $assigned = [];
        $groups = [];

        foreach ($bucket as $seed) {
            if (isset($assigned[$seed->id]) || $tokensById[$seed->id] === []) {
                continue;
            }
            $group = collect([$seed]);
            $assigned[$seed->id] = true;

            foreach ($bucket as $cand) {
                if (isset($assigned[$cand->id]) || $tokensById[$cand->id] === []) {
                    continue;
                }
                // Пакетна цена ≠ единична — различни атрибути, не конфликт.
                if ($packageById[$seed->id] !== $packageById[$cand->id]) {
                    continue;
                }
                if ($this->jaccard($tokensById[$seed->id], $tokensById[$cand->id]) >= $threshold) {
                    $group->push($cand);
                    $assigned[$cand->id] = true;
                }
            }

            if ($group->count() >= 2 && $this->distinctValues($group) >= 2) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Създава open конфликт, ако вече не е известен (open/ignored със същия
     * signature). Връща true при създаване.
     *
     * @param  Collection<int, KnowledgeFact>  $facts
     */
    private function recordConflict(Company $company, Collection $facts): bool
    {
        $ids = $facts->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $signature = hash('sha256', implode(',', $ids));

        $known = KnowledgeConflict::where('company_id', $company->id)
            ->where('signature', $signature)
            ->whereIn('status', ['open', 'ignored'])
            ->exists();

        if ($known) {
            return false;
        }

        // Най-описателното (най-дълго) име става заглавие на конфликта.
        $subject = (string) $facts->sortByDesc(fn (KnowledgeFact $f) => mb_strlen((string) $f->name))->first()->name;

        KnowledgeConflict::create([
            'company_id' => $company->id,
            'category' => $facts->first()->category,
            'location' => $facts->first()->location,
            'subject' => mb_substr($subject, 0, 300),
            'fact_ids' => $ids,
            'status' => 'open',
            'signature' => $signature,
        ]);

        return true;
    }

    /**
     * Съдържателните думи на име: lowercase, ≥3 символа, без генеричните думи
     * (цена/лазерна/епилация/…). Остатъкът (зона + пол) определя „едно и също нещо".
     *
     * @return array<int, string>
     */
    private function contentTokens(string $name): array
    {
        $stopwords = array_map('mb_strtolower', (array) config('services.knowledge.conflict_stopwords', []));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($parts as $token) {
            if (mb_strlen($token) >= 3 && ! in_array($token, $stopwords, true)) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /** Пакетна цена („пакет …") е различен атрибут от единичната — не конфликт. */
    private function isPackage(string $name): bool
    {
        return mb_stripos($name, 'пакет') !== false;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /** @param  Collection<int, KnowledgeFact>  $facts */
    private function distinctValues(Collection $facts): int
    {
        return $facts->map(fn (KnowledgeFact $f) => $this->normalizeValue((string) $f->value))->unique()->count();
    }

    private function sameValue(string $a, string $b): bool
    {
        return $this->normalizeValue($a) === $this->normalizeValue($b);
    }

    private function normalizeValue(string $v): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($v)));
    }
}
