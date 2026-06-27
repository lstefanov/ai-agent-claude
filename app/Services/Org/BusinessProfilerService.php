<?php

namespace App\Services\Org;

use App\Models\BusinessProfile;
use App\Models\Company;
use App\Services\BraveSearchService;
use App\Services\CrawlService;
use App\Services\GeneratorService;
use App\Services\GooglePlacesService;
use App\Support\PromptData;
use Illuminate\Support\Facades\Log;

/**
 * Фаза 2 на потока на Управителя (§7): проучва бизнеса през СЪЩЕСТВУВАЩИТЕ services
 * (никакви директни HTTP извиквания тук) и синтезира ситуационен анализ + болки.
 * Деградира МЕКО: липсва Brave/crawl → ползва наличните източници (Google Places + сайт),
 * без да блокира онбординга.
 */
class BusinessProfilerService
{
    public function __construct(
        private BraveSearchService $brave,
        private CrawlService $crawl,
        private GooglePlacesService $places,
        private GeneratorService $generator,
    ) {}

    /**
     * Оркестрира проучването: сайт (crawl) + уеб (Brave, ако е конфигуриран) + ревюта
     * (Google Places). Записва в business_profiles.research. Връща структуриран синтез.
     *
     * @return array<string, mixed>
     */
    public function research(Company $company): array
    {
        $research = ['sources' => [], 'site' => null, 'web' => [], 'reviews' => null];

        // Сайт — crawl офлайн (CRAWL_SERVICE_URL празен) → fetchPage връща null, без crash.
        if ($company->website_url) {
            $page = $this->crawl->fetchPage($company->website_url);
            if ($page) {
                $research['site'] = [
                    'title' => $page['title'] ?? null,
                    'meta' => $page['meta_description'] ?? null,
                    'excerpt' => mb_substr((string) ($page['markdown'] ?? ''), 0, 4000),
                ];
                $research['sources'][] = 'website';
            }
        }

        // Уеб търсене — само ако Brave е конфигуриран (иначе пропусни, не хвърляй).
        if (! empty(config('services.brave.api_key'))) {
            try {
                $query = trim($company->name.' '.((string) $company->industry).' добри практики');
                $hits = $this->brave->search($query, 5);
                $research['web'] = array_map(fn ($r) => [
                    'title' => $r['title'] ?? '',
                    'url' => $r['url'] ?? '',
                    'desc' => $r['description'] ?? '',
                ], array_slice($hits, 0, 5));
                if ($research['web']) {
                    $research['sources'][] = 'web_search';
                }
            } catch (\Throwable $e) {
                Log::info('[Profiler] web search skipped: '.$e->getMessage());
            }
        }

        // Ревюта — Google Places (деградира до null, без crash).
        if ($this->places->isAvailable()) {
            $reviews = $this->places->reviewsFor(trim($company->name.' '.((string) $company->industry)));
            if ($reviews) {
                $research['reviews'] = $reviews;
                $research['sources'][] = 'google_reviews';
            }
        }

        BusinessProfile::updateOrCreate(
            ['company_id' => $company->id],
            ['research' => $research, 'status' => 'researching'],
        );

        return $research;
    }

    /**
     * Синтезен LLM ход над research → ситуационен анализ + pain_points (на български).
     * Записва в business_profiles. Връща анализа.
     */
    public function analyze(Company $company): string
    {
        $profile = $company->businessProfile;
        $research = $profile?->research ?? [];

        $system = 'Ти си бизнес анализатор. Върху подадените данни направи КРАТЪК ситуационен '
            .'анализ на бизнеса на български: силни и слаби страни, и 3–5 КОНКРЕТНИ болки (pain points), '
            .'всяка на отделен ред с тире. Бъди конкретен, без вода. Ако данните са оскъдни, кажи кои '
            .'въпроси трябва да се изяснят с интервю. '.PromptData::NO_TECH_TERMS;
        $user = "Фирма: {$company->name}\nБранш: ".((string) $company->industry ?: 'неуточнен')
            ."\nДанни от проучването:\n".json_encode(PromptData::humanize($research), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $analysis = $this->generator->chat($system, $user, ['temperature' => 0.4, 'num_predict' => 1200]);

        BusinessProfile::updateOrCreate(
            ['company_id' => $company->id],
            [
                'situational_analysis' => $analysis,
                'pain_points' => $this->extractPains($analysis),
                'status' => 'interviewing',
            ],
        );

        return $analysis;
    }

    /** Извлича болките от анализа (редове-bullet точки). */
    private function extractPains(string $text): array
    {
        $pains = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if (preg_match('/^[\-\*•\d]+[.\)]?\s+(.{6,})$/u', $line, $m)) {
                $pains[] = trim($m[1]);
            }
        }

        return array_slice($pains, 0, 6);
    }

    /**
     * Задължителният синтез след интервюто (§3-part understanding): над research +
     * ситуационния анализ + интервюто извежда ВСИЧКИ проблеми, ВСИЧКИ нужди и НЯКОЛКО
     * НОВИ възможности за растеж. Идемпотентен (прескача при вече направен синтез).
     * Записва на профила; маркерът `synthesis_completed_at` се вдига САМО при успех
     * (провал не блокира онбординга — пробва се пак при дизайна).
     */
    public function synthesizeFeedback(BusinessProfile $profile, ?callable $onStage = null): void
    {
        if ($profile->synthesis_completed_at) {
            return;
        }

        $onStage && $onStage('Обобщавам проблемите, нуждите и възможностите…');
        $company = $profile->company;

        $system = 'Ти си бизнес консултант. Върху проучването, ситуационния анализ и интервюто извлечи на '
            .'български ТРИ изчерпателни списъка: (1) ПРОБЛЕМИ — всички конкретни проблеми, които бизнесът '
            .'трябва да реши; (2) НУЖДИ — всичко, от което бизнесът има нужда, за да работи и расте; '
            .'(3) ВЪЗМОЖНОСТИ — НЯКОЛКО НОВИ, конкретни идеи, които биха помогнали на бизнеса да се доразвие '
            .'(предложи нови неща, не просто преповтаряй проблемите). Всяка точка — кратка и конкретна. '
            .'Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS;

        $user = "Фирма: {$company?->name} ({$company?->industry})\n"
            .'Ситуационен анализ:'."\n".((string) $profile->situational_analysis ?: '(няма)')
            ."\n\n".'Болки от анализа:'."\n".implode("\n", array_map(fn ($p) => '- '.$p, (array) $profile->pain_points))
            ."\n\n".'Проучване:'."\n".json_encode(PromptData::humanize((array) $profile->research), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\n".'Интервю (въпроси + отговори):'."\n".json_encode(PromptData::humanize((array) $profile->interview_transcript), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_synthesis', $this->synthesisSchema(), [
                'temperature' => 0.5, 'num_predict' => 1800,
            ]);
        } catch (\Throwable $e) {
            Log::info('[Profiler] synthesis failed: '.$e->getMessage());

            return;
        }

        $clean = fn ($list) => array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) $list)));

        $profile->update([
            'problems' => $clean($raw['problems'] ?? []),
            'needs' => $clean($raw['needs'] ?? []),
            'opportunities' => $clean($raw['opportunities'] ?? []),
            'synthesis_completed_at' => now(),
        ]);
    }

    /** Строга схема (OpenAI strict Structured Outputs): три списъка от низове. */
    private function synthesisSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'problems' => ['type' => 'array', 'items' => ['type' => 'string']],
                'needs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['problems', 'needs', 'opportunities'],
        ];
    }
}
