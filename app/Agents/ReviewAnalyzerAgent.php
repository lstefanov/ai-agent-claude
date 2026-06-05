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

        // ── 1. On-page reviews / testimonials ───────────────────────────────
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

        // ── 2. External review profiles (Google / Facebook) ─────────────────
        if ($domain !== '' && $this->hasTool('web_search')) {
            $query   = "\"{$domain}\" отзиви OR ревюта OR reviews site:google.com OR site:facebook.com OR site:trustpilot.com";
            $results = $this->useTool('web_search', ['query' => $query]);
            if ($results) {
                $sources[] = "--- Външни резултати за ревюта на {$domain} ---\n{$results}";
            }
        }

        // ── 3. Specialised Google Maps / Google Business reviews ────────────
        if ($domain !== '') {
            $maps = $this->fetchGoogleMapsReviews($domain);
            if ($maps) {
                $sources[] = $maps;
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
     * Locate the business's Google Maps / Google Business profile via search and
     * scrape it. Falls back to the raw search snippets when no scrapeable Google
     * URL is found (or the page can't be rendered server-side).
     */
    private function fetchGoogleMapsReviews(string $domain): ?string
    {
        if (! $this->hasTool('web_search')) {
            return null;
        }

        $results = $this->useTool('web_search', ['query' => "{$domain} Google Maps отзиви ревюта"]);
        if (! $results) {
            return null;
        }

        // Prefer an actual Google Maps / Google review URL from the results.
        if (preg_match('~https?://[^\s)\]]*google\.[^\s)\]]*(?:maps|search|reviews)[^\s)\]]*~i', $results, $m)
            && $this->hasTool('scrape_page')) {
            $scraped = $this->useTool('scrape_page', ['url' => $m[0]]);
            if ($scraped && $scraped !== 'Scraping not available for this page.') {
                return "--- Google Maps/Business ревюта ({$m[0]}) ---\n{$scraped}";
            }
        }

        // No scrapeable profile — hand the search snippets to the LLM; they often
        // contain the star rating and a few review excerpts.
        return "--- Google резултати за ревюта на {$domain} ---\n{$results}";
    }
}
