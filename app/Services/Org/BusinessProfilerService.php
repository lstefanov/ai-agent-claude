<?php

namespace App\Services\Org;

use App\Jobs\IngestUrlResourceJob;
use App\Models\BusinessProfile;
use App\Models\Company;
use App\Models\KnowledgeResource;
use App\Services\BraveSearchService;
use App\Services\CrawlService;
use App\Services\GeneratorService;
use App\Services\GooglePlacesService;
use App\Services\WebPageCacheService;
use App\Support\JsonSchema;
use App\Support\PromptData;
use Illuminate\Support\Facades\Log;

/**
 * Фаза 2 на потока на Управителя (§7): проучва бизнеса през СЪЩЕСТВУВАЩИТЕ services
 * (никакви директни HTTP извиквания тук) и синтезира evidence-driven профил.
 * Деградира МЕКО: липсва Brave/crawl/Places → пази пропуските и интервюто пита за тях.
 */
class BusinessProfilerService
{
    private const RESEARCH_VERSION = 2;

    private const MAX_SITE_PAGES = 14;

    private const MAX_EVIDENCE_ITEMS = 80;

    private const SNIPPET_CHARS = 1400;

    public function __construct(
        private BraveSearchService $brave,
        private CrawlService $crawl,
        private GooglePlacesService $places,
        private GeneratorService $generator,
        private WebPageCacheService $webCache,
    ) {}

    /**
     * Оркестрира проучването: bounded BFS сайт + Brave намерения + Google Places/ревюта.
     * Записва versioned `business_profiles.research` и връща суровия evidence пакет.
     *
     * @return array<string, mixed>
     */
    public function research(Company $company, ?callable $onStage = null): array
    {
        $research = $this->emptyResearch($company);
        $evidence = [];
        $sitePages = [];
        $webSearches = [];
        $competitors = [];
        $channels = [];
        $offers = [];
        $reviews = null;

        if ($company->website_url) {
            $onStage && $onStage('Обхождам сайта на фирмата…');
            $sitePages = $this->collectSiteEvidence($company, $evidence, $channels, $offers, $onStage);
            if ($sitePages !== []) {
                $research['sources'][] = 'website_crawl';
            }
        } else {
            $research['gaps'][] = $this->gap(
                'website_missing',
                'Има ли сайт, каталог, социални профили или друг публичен източник, който да използвам?',
                'В профила няма сайт, затова публичният контекст е ограничен.',
                'medium',
            );
        }

        if (! empty(config('services.brave.api_key'))) {
            $onStage && $onStage('Търся външни сигнали и конкуренти…');
            foreach ($this->searchQueries($company) as $intent => $query) {
                try {
                    $hits = $this->brave->search($query, 6);
                    $webSearches[] = $this->shapeSearchResults($intent, $query, $hits, $evidence);
                    $channels = array_merge($channels, $this->channelsFromUrls(array_column($hits, 'url'), 'web_'.$intent));
                    if ($intent === 'competitors' || $intent === 'market') {
                        $competitors = array_merge($competitors, $this->competitorCandidatesFromResults($company, $hits));
                    }
                    $research['sources'][] = 'brave_'.$intent;
                } catch (\Throwable $e) {
                    Log::info('[Profiler] web search skipped: '.$e->getMessage(), ['intent' => $intent, 'company_id' => $company->id]);
                }
            }
        }

        if ($this->places->isAvailable()) {
            $onStage && $onStage('Проверявам публични оценки и отзиви…');
            $reviews = $this->places->reviewsFor($this->placesQuery($company));
            if ($reviews) {
                $this->appendReviewEvidence($reviews, $evidence);
                $research['sources'][] = 'google_reviews';
            }
        }

        $research['sources'] = array_values(array_unique($research['sources']));
        $research['evidence'] = array_slice($evidence, 0, self::MAX_EVIDENCE_ITEMS);
        $research['competitors'] = $this->dedupeCompetitors($competitors);
        $research['customer_voice'] = $this->customerVoiceFromReviews($reviews);
        $research['channels'] = $this->dedupeChannels($channels);
        $research['offers'] = $this->dedupeOffers($offers);
        $research['raw'] = [
            'site_pages' => $sitePages,
            'web_searches' => $webSearches,
            'reviews' => $reviews,
        ];
        $research['gaps'] = $this->mergeGaps($research['gaps'], $this->defaultGaps($company, $research));

        BusinessProfile::updateOrCreate(
            ['company_id' => $company->id],
            ['research' => $research, 'status' => 'researching'],
        );

        return $research;
    }

    /**
     * Soft ingest на сайта в Knowledge Base. Това е отделна queued операция с
     * `knowledge_ingest` billing context; не блокира онбординга.
     */
    public function queueKnowledgeIngest(Company $company, ?callable $onStage = null): ?KnowledgeResource
    {
        $url = trim((string) $company->website_url);
        if ($url === '') {
            return null;
        }

        $normalized = $this->webCache->normalizeUrl($url);
        if ($normalized === null) {
            return null;
        }

        $urlHash = hash('sha256', $normalized);
        $title = parse_url($normalized, PHP_URL_HOST).(parse_url($normalized, PHP_URL_PATH) ?: '');

        $resource = KnowledgeResource::where('company_id', $company->id)
            ->where('url_hash', $urlHash)
            ->first();

        if ($resource && $resource->status === 'processing') {
            return $resource;
        }

        $payload = [
            'type' => 'url',
            'title' => $title,
            'url' => $normalized,
            'url_hash' => $urlHash,
            'status' => 'pending',
            'error' => null,
        ];

        $resource = $resource
            ? tap($resource)->update($payload)
            : $company->knowledgeResources()->create($payload);

        $onStage && $onStage('Пускам сайта към базата знания…');
        IngestUrlResourceJob::dispatch($resource->id);

        return $resource;
    }

    /**
     * Структуриран LLM синтез над evidence пакета → report + suggested_areas + gaps.
     * Записва `situational_analysis`, `pain_points` и обогатява `research`.
     */
    public function analyze(Company $company): string
    {
        $profile = BusinessProfile::firstOrCreate(
            ['company_id' => $company->id],
            ['status' => 'researching'],
        );
        $research = $this->ensureResearchShape((array) $profile->research, $company);

        $system = 'Ти си старши бизнес анализатор за AI организация. Имаш evidence пакет за фирма и трябва да върнеш '
            .'структуриран профил на български. Не измисляй факти: когато нещо не се вижда от evidence, го сложи в gaps. '
            .'Предложи области за интервю само ако има причина или силна хипотеза. source_ids трябва да са реални id-та от evidence. '
            .'Въпросите в gaps да са малко, но решаващи: цели, капацитет, bottlenecks, приоритети, бюджет/честота и предпочитани автоматизации. '
            .'Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS;

        $user = "Фирма: {$company->name}\nБранш: ".((string) $company->industry ?: 'неуточнен')
            ."\nСайт: ".((string) $company->website_url ?: 'няма')
            ."\n\nПознати области за интервю (domain => label):\n".json_encode($this->interviewAreaLabels(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nEvidence пакет:\n".json_encode(PromptData::humanize($this->researchForPrompt($research)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_research', $this->researchSchema(), [
                'temperature' => 0.35,
                'num_predict' => 2400,
            ]);
            $research = $this->mergeResearchAnalysis($company, $research, $raw);
        } catch (\Throwable $e) {
            Log::info('[Profiler] research synthesis fallback: '.$e->getMessage(), ['company_id' => $company->id]);
            $research = $this->fallbackResearchAnalysis($company, $research);
        }

        $analysis = $this->analysisMarkdown($research);
        $pains = $this->cleanList($research['pain_points'] ?? []);
        if ($pains === []) {
            $pains = $this->extractPains($analysis);
        }

        $profile->update([
            'research' => $research,
            'situational_analysis' => $analysis,
            'pain_points' => array_slice($pains, 0, 8),
            'status' => 'interviewing',
        ]);

        return $analysis;
    }

    /**
     * Задължителният синтез след интервюто (§3-part understanding): над research +
     * ситуационния анализ + интервюто извежда ВСИЧКИ проблеми, ВСИЧКИ нужди и НЯКОЛКО
     * НОВИ възможности за растеж. Идемпотентен (прескача при вече направен синтез).
     */
    public function synthesizeFeedback(BusinessProfile $profile, ?callable $onStage = null): void
    {
        $version = $profile->transcriptVersion();
        if ($profile->synthesis_completed_at && (int) $profile->synthesis_version === $version) {
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
            'synthesis_version' => $version,
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyResearch(Company $company): array
    {
        return [
            'version' => self::RESEARCH_VERSION,
            'generated_at' => now()->toISOString(),
            'company' => [
                'name' => $company->name,
                'industry' => $company->industry,
                'website_url' => $company->website_url,
            ],
            'report' => [
                'summary' => '',
                'positioning' => '',
                'strengths' => [],
                'risks' => [],
                'likely_needs' => [],
                'automation_opportunities' => [],
                'analysis_markdown' => '',
            ],
            'evidence' => [],
            'suggested_areas' => [],
            'gaps' => [],
            'competitors' => [],
            'customer_voice' => [
                'rating' => null,
                'review_count' => null,
                'themes' => [],
                'praise' => [],
                'complaints' => [],
                'sample_quotes' => [],
            ],
            'channels' => [],
            'offers' => [],
            'sources' => [],
            'raw' => [],
            'pain_points' => [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function collectSiteEvidence(Company $company, array &$evidence, array &$channels, array &$offers, ?callable $onStage): array
    {
        $pages = [];

        try {
            $pages = $this->crawl->crawlSiteBfs(
                (string) $company->website_url,
                self::MAX_SITE_PAGES,
                deadlineTs: microtime(true) + 150,
                onProgress: function (int $parsed, int $discovered) use ($onStage) {
                    $onStage && $onStage("Обхождам сайта: {$parsed} страници, {$discovered} открити…");
                },
            );
        } catch (\Throwable $e) {
            Log::info('[Profiler] site BFS skipped: '.$e->getMessage(), ['company_id' => $company->id]);
        }

        if ($pages === [] && $company->website_url) {
            $page = $this->crawl->fetchPage((string) $company->website_url);
            if ($page) {
                $pages = [$page];
            }
        }

        $sitePages = [];
        foreach (array_slice($pages, 0, self::MAX_SITE_PAGES) as $i => $page) {
            $id = 'site_'.($i + 1);
            $snippet = $this->cleanSnippet((string) ($page['markdown'] ?? ''), self::SNIPPET_CHARS);
            $title = (string) (($page['title'] ?? null) ?: ($page['url'] ?? 'Страница'));
            $url = (string) ($page['url'] ?? '');

            $sitePages[] = [
                'id' => $id,
                'url' => $url,
                'title' => $title,
                'meta' => $page['meta_description'] ?? null,
                'excerpt' => $snippet,
                'links_count' => count((array) ($page['links'] ?? [])),
                'from_cache' => (bool) ($page['from_cache'] ?? false),
            ];

            $evidence[] = $this->evidence($id, 'site_page', $title, $url, trim(($page['meta_description'] ?? '')."\n".$snippet), 0.88);
            $channels = array_merge($channels, $this->channelsFromUrls((array) ($page['links'] ?? []), $id));
            $offers = array_merge($offers, $this->offerHintsFromPage($page, $id));
        }

        $normalized = $this->webCache->normalizeUrl((string) $company->website_url);
        if ($normalized !== null) {
            $channels[] = [
                'type' => 'website',
                'name' => 'Официален сайт',
                'url' => $normalized,
                'notes' => 'Основен публичен източник за фирмата.',
                'source_ids' => $sitePages !== [] ? [$sitePages[0]['id']] : [],
            ];
        }

        return $sitePages;
    }

    /** @return array<string, string> */
    private function searchQueries(Company $company): array
    {
        $industry = trim((string) $company->industry);
        $name = trim((string) $company->name);

        return array_filter([
            'company' => trim($name.' '.$industry),
            'competitors' => trim($industry.' конкуренти '.$name.' България'),
            'market' => trim($industry.' клиентски нужди добри практики автоматизация'),
        ]);
    }

    /** @param array<int, array<string, mixed>> $hits */
    private function shapeSearchResults(string $intent, string $query, array $hits, array &$evidence): array
    {
        $results = [];
        foreach (array_slice($hits, 0, 6) as $i => $hit) {
            $id = 'web_'.$intent.'_'.($i + 1);
            $title = trim((string) ($hit['title'] ?? ''));
            $url = trim((string) ($hit['url'] ?? ''));
            $desc = trim((string) ($hit['description'] ?? ''));

            $results[] = ['id' => $id, 'title' => $title, 'url' => $url, 'desc' => $desc];
            $evidence[] = $this->evidence($id, 'web_search', $title, $url, $desc, 0.62);
        }

        return ['intent' => $intent, 'query' => $query, 'results' => $results];
    }

    /** @param array<string, mixed> $reviews */
    private function appendReviewEvidence(array $reviews, array &$evidence): void
    {
        $evidence[] = $this->evidence(
            'places_summary',
            'google_places',
            $reviews['name'] ?? 'Google Places',
            null,
            trim(($reviews['address'] ?? '')."\nОценка: ".($reviews['rating'] ?? '—').' / '.($reviews['total'] ?? 0).' ревюта'),
            0.76,
        );

        foreach (array_slice((array) ($reviews['reviews'] ?? []), 0, 5) as $i => $review) {
            $evidence[] = $this->evidence(
                'review_'.($i + 1),
                'customer_review',
                'Google review '.($i + 1),
                null,
                trim('Оценка: '.($review['rating'] ?? '—')."\n".(string) ($review['text'] ?? '')),
                0.74,
            );
        }
    }

    private function evidence(string $id, string $type, ?string $title, ?string $url, string $snippet, float $confidence): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title ?: $id,
            'url' => $url,
            'snippet' => mb_substr($this->cleanSnippet($snippet, self::SNIPPET_CHARS), 0, self::SNIPPET_CHARS),
            'confidence' => $this->clampConfidence($confidence),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function competitorCandidatesFromResults(Company $company, array $hits): array
    {
        $ownHost = $this->host((string) $company->website_url);
        $out = [];

        foreach (array_slice($hits, 0, 8) as $hit) {
            $url = trim((string) ($hit['url'] ?? ''));
            $host = $this->host($url);
            if ($host === '' || ($ownHost !== '' && $host === $ownHost) || $this->isSocialHost($host)) {
                continue;
            }

            $out[] = [
                'name' => trim((string) ($hit['title'] ?? $host)),
                'url' => $url,
                'positioning' => trim((string) ($hit['description'] ?? '')),
                'source_ids' => [],
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function channelsFromUrls(array $urls, string $sourceId): array
    {
        $channels = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);
            $host = $this->host($url);
            if ($host === '') {
                continue;
            }

            $type = match (true) {
                str_contains($host, 'facebook.') => 'facebook',
                str_contains($host, 'instagram.') => 'instagram',
                str_contains($host, 'linkedin.') => 'linkedin',
                str_contains($host, 'tiktok.') => 'tiktok',
                str_contains($host, 'youtube.') || str_contains($host, 'youtu.be') => 'youtube',
                str_contains($host, 'maps.google.') || str_contains($host, 'google.') => 'google',
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $channels[] = [
                'type' => $type,
                'name' => ucfirst($type),
                'url' => $url,
                'notes' => 'Открит публичен канал.',
                'source_ids' => [$sourceId],
            ];
        }

        return $channels;
    }

    /** @param array<string, mixed> $page */
    private function offerHintsFromPage(array $page, string $sourceId): array
    {
        $out = [];
        $url = (string) ($page['url'] ?? '');
        $title = trim((string) ($page['title'] ?? ''));

        if ($title !== '' && $this->looksLikeOffer($title, $url)) {
            $out[] = ['name' => $title, 'description' => $page['meta_description'] ?? '', 'source_ids' => [$sourceId]];
        }

        foreach (preg_split('/\r?\n/', (string) ($page['markdown'] ?? '')) as $line) {
            $line = trim($line);
            if (! preg_match('/^#{1,3}\s+(.{4,90})$/u', $line, $m)) {
                continue;
            }
            $heading = trim($m[1]);
            if ($this->looksLikeOffer($heading, $url)) {
                $out[] = ['name' => $heading, 'description' => '', 'source_ids' => [$sourceId]];
            }
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    private function looksLikeOffer(string $text, string $url): bool
    {
        $haystack = mb_strtolower($text.' '.$url);

        return (bool) preg_match('/услуг|продукт|цени|пакет|процедур|курс|меню|shop|service|pricing|product/u', $haystack);
    }

    private function customerVoiceFromReviews(?array $reviews): array
    {
        if (! $reviews) {
            return [
                'rating' => null,
                'review_count' => null,
                'themes' => [],
                'praise' => [],
                'complaints' => [],
                'sample_quotes' => [],
            ];
        }

        $quotes = [];
        foreach (array_slice((array) ($reviews['reviews'] ?? []), 0, 5) as $i => $review) {
            $text = trim((string) ($review['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $quotes[] = [
                'text' => mb_substr($text, 0, 260),
                'rating' => $review['rating'] ?? null,
                'source_id' => 'review_'.($i + 1),
            ];
        }

        return [
            'rating' => $reviews['rating'] ?? null,
            'review_count' => $reviews['total'] ?? null,
            'themes' => [],
            'praise' => [],
            'complaints' => [],
            'sample_quotes' => $quotes,
        ];
    }

    private function mergeResearchAnalysis(Company $company, array $research, array $raw): array
    {
        $research = $this->ensureResearchShape($research, $company);
        $validIds = array_fill_keys(array_map('strval', array_column((array) $research['evidence'], 'id')), true);

        $research['generated_at'] = now()->toISOString();
        $research['report'] = [
            'summary' => trim((string) data_get($raw, 'report.summary', '')),
            'positioning' => trim((string) data_get($raw, 'report.positioning', '')),
            'strengths' => $this->cleanList(data_get($raw, 'report.strengths', [])),
            'risks' => $this->cleanList(data_get($raw, 'report.risks', [])),
            'likely_needs' => $this->cleanList(data_get($raw, 'report.likely_needs', [])),
            'automation_opportunities' => $this->cleanList(data_get($raw, 'report.automation_opportunities', [])),
            'analysis_markdown' => trim((string) data_get($raw, 'report.analysis_markdown', '')),
        ];
        $research['suggested_areas'] = $this->normalizeSuggestedAreas((array) ($raw['suggested_areas'] ?? []), $validIds);
        $research['gaps'] = $this->mergeGaps(
            $this->normalizeGaps((array) ($raw['gaps'] ?? []), $validIds),
            $this->defaultGaps($company, $research),
        );
        $research['competitors'] = $this->dedupeCompetitors(array_merge(
            (array) ($research['competitors'] ?? []),
            (array) ($raw['competitors'] ?? []),
        ));
        $research['customer_voice'] = $this->mergeCustomerVoice((array) ($research['customer_voice'] ?? []), (array) ($raw['customer_voice'] ?? []));
        $research['channels'] = $this->dedupeChannels(array_merge((array) ($research['channels'] ?? []), (array) ($raw['channels'] ?? [])));
        $research['offers'] = $this->dedupeOffers(array_merge((array) ($research['offers'] ?? []), (array) ($raw['offers'] ?? [])));
        $research['pain_points'] = $this->cleanList($raw['pain_points'] ?? []);

        return $research;
    }

    private function fallbackResearchAnalysis(Company $company, array $research): array
    {
        $research = $this->ensureResearchShape($research, $company);
        $sourceCount = count((array) ($research['evidence'] ?? []));

        $research['report'] = [
            'summary' => $sourceCount > 0
                ? 'Събрани са публични сигнали за бизнеса, но структурираният LLM синтез не завърши. Интервюто ще валидира важните хипотези.'
                : 'Публичните данни са оскъдни. Интервюто трябва да събере основния контекст директно от собственика.',
            'positioning' => '',
            'strengths' => $sourceCount > 0 ? ['Има публични следи, които могат да се използват като начална база.'] : [],
            'risks' => ['Липсват вътрешни данни за цели, капацитет, процеси и bottlenecks.'],
            'likely_needs' => ['Изясняване на приоритети, оперативни bottlenecks и желани автоматизации.'],
            'automation_opportunities' => [],
            'analysis_markdown' => '',
        ];
        $research['suggested_areas'] = [];
        $research['gaps'] = $this->mergeGaps((array) ($research['gaps'] ?? []), $this->defaultGaps($company, $research));
        $research['pain_points'] = $research['report']['risks'];

        return $research;
    }

    /** @return array<string, mixed> */
    private function ensureResearchShape(array $research, Company $company): array
    {
        return array_replace_recursive($this->emptyResearch($company), $research, [
            'version' => self::RESEARCH_VERSION,
            'company' => [
                'name' => $company->name,
                'industry' => $company->industry,
                'website_url' => $company->website_url,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function researchForPrompt(array $research): array
    {
        return [
            'report_seed' => $research['report'] ?? [],
            'evidence' => array_map(
                fn ($item) => is_array($item)
                    ? array_merge($item, ['snippet' => mb_substr((string) ($item['snippet'] ?? ''), 0, 800)])
                    : $item,
                array_slice((array) ($research['evidence'] ?? []), 0, 40),
            ),
            'customer_voice' => $research['customer_voice'] ?? [],
            'competitor_candidates' => $research['competitors'] ?? [],
            'channels' => $research['channels'] ?? [],
            'offer_hints' => $research['offers'] ?? [],
            'known_gaps' => $research['gaps'] ?? [],
            'raw' => [
                'site_pages' => array_slice((array) data_get($research, 'raw.site_pages', []), 0, 8),
                'web_searches' => data_get($research, 'raw.web_searches', []),
                'reviews' => data_get($research, 'raw.reviews', null),
            ],
        ];
    }

    /** @return array<string, string> */
    private function interviewAreaLabels(): array
    {
        $catalog = (array) config('organization.department_catalog', []);
        $out = [];
        foreach ((array) config('organization.interview_areas', []) as $domain) {
            $label = (string) ($catalog[$domain]['interview_label'] ?? $catalog[$domain]['title'] ?? $domain);
            if ($label !== '') {
                $out[(string) $domain] = $label;
            }
        }

        return $out;
    }

    private function analysisMarkdown(array $research): string
    {
        $report = (array) ($research['report'] ?? []);
        $provided = trim((string) ($report['analysis_markdown'] ?? ''));
        if ($provided !== '') {
            return $provided;
        }

        $lines = [];
        $summary = trim((string) ($report['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }

        foreach ([
            'Силни страни' => $report['strengths'] ?? [],
            'Рискове и болки' => $report['risks'] ?? [],
            'Вероятни нужди' => $report['likely_needs'] ?? [],
            'Възможности за автоматизация' => $report['automation_opportunities'] ?? [],
        ] as $title => $items) {
            $items = $this->cleanList($items);
            if ($items === []) {
                continue;
            }
            $lines[] = "**{$title}:**";
            foreach ($items as $item) {
                $lines[] = '- '.$item;
            }
            $lines[] = '';
        }

        $gaps = array_slice((array) ($research['gaps'] ?? []), 0, 5);
        if ($gaps !== []) {
            $lines[] = '**Какво ще изясним в интервюто:**';
            foreach ($gaps as $gap) {
                $lines[] = '- '.(string) ($gap['question'] ?? $gap['reason'] ?? '');
            }
        }

        return trim(implode("\n", array_filter($lines, fn ($line) => $line !== null)));
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

        return array_slice($pains, 0, 8);
    }

    /** @return array<int, array<string, mixed>> */
    private function defaultGaps(Company $company, array $research): array
    {
        $gaps = [
            $this->gap('internal_goals', 'Кои са най-важните бизнес цели за следващите 3–6 месеца?', 'Целите не се виждат надеждно от публични източници.', 'high'),
            $this->gap('capacity', 'Къде е най-големият недостиг на време, хора или експертиза?', 'Капацитетът е вътрешна информация.', 'high'),
            $this->gap('bottlenecks', 'Кои процеси се бавят, повтарят се ръчно или създават грешки?', 'Bottleneck-ите определят кои асистенти ще са най-полезни.', 'high'),
            $this->gap('automation_preferences', 'Какви задачи би искал AI екипът да поеме първо и колко често?', 'Честота и предпочитания за автоматизация не могат да се извлекат отвън.', 'medium'),
            $this->gap('budget_priority', 'Има ли ограничения за бюджет, темпо или риск при автоматизациите?', 'Това влияе върху агресивността на предложената организация.', 'medium'),
        ];

        if (empty($company->website_url) || empty(data_get($research, 'raw.site_pages'))) {
            array_unshift($gaps, $this->gap('public_presence', 'Кои публични канали, оферти и аудитории са най-важни за бизнеса?', 'Нямам надежден crawl на сайт, затова трябва да валидирам публичното присъствие.', 'medium'));
        }

        return $gaps;
    }

    private function gap(string $key, string $question, string $reason, string $priority, ?string $domain = null, array $sourceIds = []): array
    {
        return [
            'key' => $key,
            'question' => $question,
            'reason' => $reason,
            'priority' => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium',
            'domain' => $domain,
            'source_ids' => array_values(array_unique(array_map('strval', $sourceIds))),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeSuggestedAreas(array $items, array $validIds): array
    {
        $areas = $this->interviewAreaLabels();
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $domain = trim((string) ($item['domain'] ?? ''));
            if (! isset($areas[$domain])) {
                continue;
            }
            $out[$domain] = [
                'domain' => $domain,
                'label' => $areas[$domain],
                'reason' => trim((string) ($item['reason'] ?? '')),
                'confidence' => $this->clampConfidence((float) ($item['confidence'] ?? 0.55)),
                'source_ids' => $this->sourceIds((array) ($item['source_ids'] ?? []), $validIds),
                'default' => (bool) ($item['default'] ?? true),
            ];
        }

        return array_slice(array_values($out), 0, 8);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeGaps(array $items, array $validIds): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_:-]+/i', '_', trim((string) ($item['key'] ?? '')));
            $question = trim((string) ($item['question'] ?? ''));
            if ($key === '' || $question === '') {
                continue;
            }
            $out[] = $this->gap(
                mb_substr($key, 0, 80),
                $question,
                trim((string) ($item['reason'] ?? '')),
                (string) ($item['priority'] ?? 'medium'),
                trim((string) ($item['domain'] ?? '')) ?: null,
                $this->sourceIds((array) ($item['source_ids'] ?? []), $validIds),
            );
        }

        return array_slice($out, 0, 10);
    }

    private function mergeGaps(array ...$groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group as $gap) {
                if (! is_array($gap)) {
                    continue;
                }
                $key = (string) ($gap['key'] ?? md5((string) ($gap['question'] ?? '')));
                if ($key === '' || isset($out[$key])) {
                    continue;
                }
                $out[$key] = $gap;
            }
        }

        return array_slice(array_values($out), 0, 12);
    }

    private function mergeCustomerVoice(array $existing, array $incoming): array
    {
        return [
            'rating' => $incoming['rating'] ?? $existing['rating'] ?? null,
            'review_count' => $incoming['review_count'] ?? $existing['review_count'] ?? null,
            'themes' => $this->cleanList(array_merge((array) ($existing['themes'] ?? []), (array) ($incoming['themes'] ?? []))),
            'praise' => $this->cleanList(array_merge((array) ($existing['praise'] ?? []), (array) ($incoming['praise'] ?? []))),
            'complaints' => $this->cleanList(array_merge((array) ($existing['complaints'] ?? []), (array) ($incoming['complaints'] ?? []))),
            'sample_quotes' => array_slice(array_values(array_merge((array) ($existing['sample_quotes'] ?? []), (array) ($incoming['sample_quotes'] ?? []))), 0, 8),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function dedupeChannels(array $channels): array
    {
        $out = [];
        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $url = trim((string) ($channel['url'] ?? ''));
            $type = trim((string) ($channel['type'] ?? ''));
            if ($url === '' || $type === '') {
                continue;
            }
            $key = mb_strtolower($type.'|'.$url);
            $out[$key] = [
                'type' => $type,
                'name' => trim((string) ($channel['name'] ?? ucfirst($type))),
                'url' => $url,
                'notes' => trim((string) ($channel['notes'] ?? '')),
                'source_ids' => $this->sourceIds((array) ($channel['source_ids'] ?? [])),
            ];
        }

        return array_slice(array_values($out), 0, 12);
    }

    /** @return array<int, array<string, mixed>> */
    private function dedupeOffers(array $offers): array
    {
        $out = [];
        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $name = trim((string) ($offer['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $out[$key] = [
                'name' => mb_substr($name, 0, 120),
                'description' => mb_substr(trim((string) ($offer['description'] ?? '')), 0, 500),
                'source_ids' => $this->sourceIds((array) ($offer['source_ids'] ?? [])),
            ];
        }

        return array_slice(array_values($out), 0, 12);
    }

    /** @return array<int, array<string, mixed>> */
    private function dedupeCompetitors(array $competitors): array
    {
        $out = [];
        foreach ($competitors as $competitor) {
            if (! is_array($competitor)) {
                continue;
            }
            $name = trim((string) ($competitor['name'] ?? ''));
            $url = trim((string) ($competitor['url'] ?? ''));
            if ($name === '' && $url === '') {
                continue;
            }
            $key = $url !== '' ? $this->host($url) : mb_strtolower($name);
            if ($key === '') {
                $key = md5($name.$url);
            }
            $out[$key] = [
                'name' => $name ?: $key,
                'url' => $url,
                'positioning' => mb_substr(trim((string) ($competitor['positioning'] ?? '')), 0, 500),
                'source_ids' => $this->sourceIds((array) ($competitor['source_ids'] ?? [])),
            ];
        }

        return array_slice(array_values($out), 0, 8);
    }

    /** @return array<int, string> */
    private function cleanList(mixed $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) $items,
        ), fn ($s) => $s !== '')));
    }

    /** @return array<int, string> */
    private function sourceIds(array $ids, array $validIds = []): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '' || ($validIds !== [] && ! isset($validIds[$id]))) {
                continue;
            }
            $out[] = $id;
        }

        return array_slice(array_values(array_unique($out)), 0, 8);
    }

    private function placesQuery(Company $company): string
    {
        return trim($company->name.' '.((string) $company->industry));
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        return preg_replace('/^www\./', '', mb_strtolower($host)) ?: '';
    }

    private function isSocialHost(string $host): bool
    {
        return str_contains($host, 'facebook.')
            || str_contains($host, 'instagram.')
            || str_contains($host, 'linkedin.')
            || str_contains($host, 'tiktok.')
            || str_contains($host, 'youtube.')
            || str_contains($host, 'google.');
    }

    private function cleanSnippet(string $text, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_substr($text, 0, $limit);
    }

    private function clampConfidence(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 2);
    }

    /** Строга схема за structured business research. */
    private function researchSchema(): array
    {
        return JsonSchema::strict([
            'type' => 'object',
            'properties' => [
                'report' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'positioning' => ['type' => 'string'],
                        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'likely_needs' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'automation_opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'analysis_markdown' => ['type' => 'string'],
                    ],
                ],
                'suggested_areas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'domain' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'default' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'gaps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'question' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'domain' => ['type' => ['string', 'null']],
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'competitors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'positioning' => ['type' => 'string'],
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'customer_voice' => [
                    'type' => 'object',
                    'properties' => [
                        'rating' => ['type' => ['number', 'null']],
                        'review_count' => ['type' => ['integer', 'null']],
                        'themes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'praise' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'complaints' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'sample_quotes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => ['type' => 'string'],
                                    'rating' => ['type' => ['number', 'null']],
                                    'source_id' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'channels' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'offers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'pain_points' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
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
