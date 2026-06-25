<?php

namespace App\Services\Org;

use App\Models\BusinessProfile;
use App\Models\Company;
use App\Services\BraveSearchService;
use App\Services\CrawlService;
use App\Services\GeneratorService;
use App\Services\GooglePlacesService;
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
            .'въпроси трябва да се изяснят с интервю.';
        $user = "Фирма: {$company->name}\nБранш: ".((string) $company->industry ?: 'неуточнен')
            ."\nДанни от проучването:\n".json_encode($research, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

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
}
