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
                $content = $this->capKeepingFooter(PageContent::stripBoilerplate($homepage), self::MAX_HOMEPAGE_CHARS);
                $extra .= "\n\n--- НАЧАЛНА СТРАНИЦА НА {$targetUrl} (основен източник за идентичността на бизнеса) ---\n{$content}";
            }
        }

        // ── Sitemap hint + explicit contact-page scrape. The real address/phone
        //    usually live on /контакти (or in the footer), NOT in the first 16K of
        //    the homepage — so we fetch the contact page(s) verbatim.
        if ($this->hasTool('discover_urls')) {
            $urlsRaw = (string) $this->useTool('discover_urls', ['url' => $targetUrl, 'max' => self::MAX_URL_HINTS]);
            $urls = array_values(array_filter(array_map('trim', explode("\n", $urlsRaw))));
            if ($urls) {
                $list = implode("\n", array_slice($urls, 0, self::MAX_URL_HINTS));
                $extra .= "\n\n--- СТРАНИЦИ ОТКРИТИ В САЙТА (".count($urls)." бр., само за ориентир за структурата — НЕ са свалени) ---\n{$list}";

                $extra .= $this->scrapeContactPages($urls);
            }
        }

        $instruction = "\n\nНа база НАЧАЛНАТА СТРАНИЦА, СТРАНИЦАТА ЗА КОНТАКТИ и структурата на сайта по-горе,"
            ." изгради компактен профил на бизнеса на БЪЛГАРСКИ. Извлечи САМО това, което реално присъства:\n"
            ."- Име на бизнеса / марка\n"
            ."- С какво се занимава (1-2 изречения)\n"
            ."- Основни направления услуги/продукти (списък)\n"
            ."- ПЪЛНИ контакти: телефон, имейл, адрес с град — взети ДОСЛОВНО от текста (не от домейна, без отгатване)\n"
            ."- Език на сайта\n"
            .'Без измислици — ако нещо липсва, напиши «не е посочено». Това е базов контекст за следващите агенти.';

        $check = ($agent->config ?? [])['self_check'] ?? [];
        if (empty($check['require_contacts'])) {
            return $this->chat($agent, $agentRun->input, $extra.$instruction);
        }

        // Self-check: the profile must carry a real contact OR explicitly state it
        // was not found — never a hallucinated one (run 71 invented a Sofia address).
        $passes = fn (string $out): bool => $this->containsContact($out)
            || (bool) preg_match('/не е посочен|не са (?:посочен|намерен)|липсва/iu', $out);

        $hint = 'В профила липсват контакти. Извлечи телефон, имейл и адрес с град ДОСЛОВНО от секцията '
            .'„СТРАНИЦА ЗА КОНТАКТИ" или от футъра на началната страница по-горе. Ако наистина ги няма в текста, '
            .'напиши „контактите не са посочени на сайта". Не измисляй и не отгатвай от домейна.';

        return $this->chatWithSelfCheck(
            $agent,
            $agentRun->input,
            $passes,
            $hint,
            (int) ($check['max_retries'] ?? 1),
            $extra.$instruction
        );
    }

    /**
     * Cap long homepage markdown while preserving the footer — the footer often
     * holds the real address/phone, which a head-only cap would drop (the homepage
     * here is ~84K chars with contacts at the very end).
     */
    private function capKeepingFooter(string $content, int $max): string
    {
        if (mb_strlen($content) <= $max) {
            return $content;
        }

        $headLen = (int) ($max * 0.75);
        $tailLen = $max - $headLen;

        return mb_substr($content, 0, $headLen)
            ."\n\n[...пропуснато...]\n\n"
            .mb_substr($content, -$tailLen);
    }

    /** Scrape up to 2 discovered contact pages verbatim so real contacts reach the model. */
    private function scrapeContactPages(array $urls): string
    {
        if (! $this->hasTool('scrape_page')) {
            return '';
        }

        $contactUrls = array_slice(array_values(array_filter(
            $urls,
            fn ($u) => is_string($u) && preg_match('/контакт|contact|kontakt|%d0%ba%d0%be%d0%bd%d1%82/iu', $u)
        )), 0, 2);

        $out = '';
        foreach ($contactUrls as $cu) {
            $c = $this->useTool('scrape_page', ['url' => $cu]);
            if ($c && $c !== 'Scraping not available for this page.') {
                $c = mb_substr(PageContent::stripBoilerplate($c), 0, 8000);
                $out .= "\n\n--- СТРАНИЦА ЗА КОНТАКТИ {$cu} (вземи телефон/имейл/адрес ДОСЛОВНО оттук) ---\n{$c}";
            }
        }

        return $out;
    }
}
