<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

/**
 * Finds and analyses REAL customer reviews for a business. Three complementary
 * sources, none of which rely on hardcoded review URLs:
 *
 *  1. On-page reviews — re-reads the site's own pages so the LLM can detect
 *     embedded testimonials / review widgets that appear in the page content.
 *  2. External profiles — a web search surfaces Google / Facebook review pages.
 *  3. Google Maps — a targeted search locates the business's Maps/Business
 *     profile and scrapes it (best-effort; JS-rendered widgets may not render
 *     server-side, in which case the search snippets still carry the signal).
 *
 * The LLM decides what is and isn't a review — we never pattern-match review text.
 */
class ReviewAnalyzerAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config    = $agent->config ?? [];
        $targetUrl = $context['target_url'] ?? $context['url'] ?? null;
        $domain    = $targetUrl
            ? strtolower(preg_replace('/^www\./i', '', parse_url($targetUrl, PHP_URL_HOST) ?? ''))
            : '';

        $sources = [];
        $haveGooglePlaces = false;

        // Business-name + region hints derived from the domain.
        $businessHint = $domain !== '' ? ucfirst(preg_replace('/\..*$/', '', $domain)) : '';
        $region = ($domain !== '' && preg_match('/\.([a-z]{2})$/i', $domain, $m)) ? strtoupper($m[1]) : null;

        // ── 1. Google Places API — the reliable source for Google reviews ───
        // (rating + count + sample reviews). Plain scraping can't reach the JS
        // reviews block, so this official API is tried FIRST.
        if ($businessHint !== '' && $this->hasTool('google_reviews')) {
            $places = $this->useTool('google_reviews', ['query' => $businessHint, 'region' => $region]);
            if ($places !== null && trim($places) !== '') {
                $sources[]        = "--- Google ревюта (Places API) ---\n{$places}";
                $haveGooglePlaces = true;
            }
        }

        // ── 2. On-page reviews / testimonials ───────────────────────────────
        // Pre-configured review URLs win if an operator set them; otherwise read
        // the target site itself and let the LLM spot any embedded reviews.
        $pagesToCheck = ! empty($config['review_urls'])
            ? (array) $config['review_urls']
            : array_filter([$targetUrl]);

        if ($this->hasTool('scrape_page')) {
            foreach ($pagesToCheck as $pageUrl) {
                $content = $this->useTool('scrape_page', ['url' => $pageUrl]);
                if ($content && $content !== 'Scraping not available for this page.') {
                    $sources[] = "--- Съдържание от {$pageUrl} (потърси вградени отзиви/testimonials/оценки) ---\n{$content}";
                }
            }
        }

        // ── 3. External review profiles (Google / Facebook) ─────────────────
        if ($domain !== '' && $this->hasTool('web_search')) {
            $query   = "\"{$domain}\" отзиви OR ревюта OR reviews site:google.com OR site:facebook.com OR site:trustpilot.com";
            $results = $this->useTool('web_search', ['query' => $query]);
            if ($results) {
                $sources[] = "--- Външни резултати за ревюта на {$domain} ---\n{$results}";
            }
        }

        // ── 4. Google scrape via Crawl4AI — fallback ONLY if Places gave nothing
        // (e.g. no API key). Best-effort; Google often blocks the headless render.
        if (! $haveGooglePlaces && $domain !== '') {
            $google = $this->fetchGoogleReviews($domain, $businessHint);
            if ($google) {
                $sources[] = $google;
            }
        }

        $extraContext = $sources ? "\n\n".implode("\n\n", $sources) : '';

        $instruction = $extraContext !== ''
            ? "\n\nАнализирай реалните ревюта от източниците по-горе и предостави на БЪЛГАРСКИ:\n"
              ."- Общ тон и sentiment (позитивен / неутрален / негативен)\n"
              ."- Средна оценка (ако е посочена) и брой ревюта\n"
              ."- Повтарящи се похвали и оплаквания\n"
              ."- Конкретни цитати с източник (ако са налични)\n"
              ."- Кратко заключение\n"
              ."Ако в източниците няма реални ревюта — кажи ясно: 'Не са намерени публични ревюта.'\n"
              ."НИКОГА не измисляй ревюта, оценки или коментари."
            : "\n\nНе са открити източници с ревюта. Напиши ясно: 'Не са намерени публични ревюта за този бизнес.'"
              ." НИКОГА не измисляй ревюта. Отговаряй на БЪЛГАРСКИ.";

        return $this->chat($agent, $agentRun->input, $extraContext.$instruction);
    }

    /**
     * Fetch Google reviews by scraping a constructed Google search URL THROUGH
     * the scrape_page tool (Crawl4AI). Crawl4AI opens the URL in a real headless
     * browser, so Google's JS-rendered reviews block comes back as text — no
     * separate browser integration needed. Falls back to plain web-search snippets
     * when scraping yields nothing (Google consent page / bot block).
     */
    private function fetchGoogleReviews(string $domain, string $businessHint): ?string
    {
        $query = trim($businessHint) !== '' ? $businessHint : $domain;

        // 1) Render a Google search for the business' reviews via Crawl4AI.
        if ($this->hasTool('scrape_page')) {
            $googleUrl = 'https://www.google.com/search?hl=bg&gl=bg&q='.rawurlencode($query.' reviews отзиви');
            $scraped   = $this->useTool('scrape_page', ['url' => $googleUrl]);
            if ($scraped && $scraped !== 'Scraping not available for this page.' && trim($scraped) !== '') {
                return "--- Google ревюта за \"{$query}\" (рендирано през Crawl4AI) ---\n{$scraped}";
            }
        }

        // 2) Fallback: plain web-search snippets (often carry the rating + excerpts).
        if ($this->hasTool('web_search')) {
            $results = $this->useTool('web_search', ['query' => "{$query} Google reviews отзиви ревюта"]);
            if ($results) {
                return "--- Google резултати за ревюта на \"{$query}\" ---\n{$results}";
            }
        }

        return null;
    }
}
