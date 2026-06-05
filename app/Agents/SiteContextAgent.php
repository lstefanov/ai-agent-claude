<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Support\PageContent;

/**
 * The FIRST agent in a site-analysis flow. It does NOT crawl the whole site —
 * it scrapes only the homepage and asks the LLM to understand it, producing a
 * compact business identity (name, what the business does, main service areas,
 * contacts, languages). This shared context is fanned out to BOTH the site
 * explorer and the review analyzer so neither branch has to re-derive "who is
 * this business" and the review search has a real business name to work with.
 *
 * No hardcoded keуворds or URL patterns: the page is handed to the model as-is and
 * the model decides what the content means.
 */
class SiteContextAgent extends BaseAgent
{
    /** Max chars of homepage markdown handed to the model. */
    private const MAX_HOMEPAGE_CHARS = 16000;

    /** How many discovered page URLs to list as a sitemap hint (titles only, not scraped). */
    private const MAX_URL_HINTS = 40;

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $targetUrl = $context['target_url'] ?? $context['url'] ?? null;

        if (empty($targetUrl)) {
            // No site to profile — behave as a plain LLM pass so the flow still runs.
            return $this->chat($agent, $agentRun->input);
        }

        $extra = '';

        // ── Homepage: the single most important page for business identity ──
        if ($this->hasTool('scrape_page')) {
            $homepage = $this->useTool('scrape_page', ['url' => $targetUrl]);
            if ($homepage && $homepage !== 'Scraping not available for this page.') {
                $content = PageContent::stripBoilerplate($homepage);
                if (mb_strlen($content) > self::MAX_HOMEPAGE_CHARS) {
                    $content = mb_substr($content, 0, self::MAX_HOMEPAGE_CHARS);
                }
                $extra .= "\n\n--- НАЧАЛНА СТРАНИЦА НА {$targetUrl} (основен източник за идентичността на бизнеса) ---\n{$content}";
            }
        }

        // ── Sitemap hint: just the list of page URLs, so the model can see the
        //    shape of the site (how many service/price/contact pages exist).
        if ($this->hasTool('discover_urls')) {
            $urlsRaw = (string) $this->useTool('discover_urls', ['url' => $targetUrl, 'max' => self::MAX_URL_HINTS]);
            $urls    = array_values(array_filter(array_map('trim', explode("\n", $urlsRaw))));
            if ($urls) {
                $list = implode("\n", array_slice($urls, 0, self::MAX_URL_HINTS));
                $extra .= "\n\n--- СТРАНИЦИ ОТКРИТИ В САЙТА (".count($urls)." бр., само за ориентир за структурата — НЕ са свалени) ---\n{$list}";
            }
        }

        $instruction = "\n\nНа база НАЧАЛНАТА СТРАНИЦА и структурата на сайта по-горе, изгради компактен"
            ." профил на бизнеса на БЪЛГАРСКИ. Извлечи САМО това, което реално присъства:\n"
            ."- Име на бизнеса / марка\n"
            ."- С какво се занимава (1-2 изречения)\n"
            ."- Основни направления услуги/продукти (списък)\n"
            ."- Контакти и локация (ако са налични)\n"
            ."- Език на сайта\n"
            ."Без измислици — ако нещо липсва, пропусни го. Това е базов контекст за следващите агенти.";

        return $this->chat($agent, $agentRun->input, $extra.$instruction);
    }
}
