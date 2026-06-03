<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class ReviewAnalyzerAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config     = $agent->config ?? [];
        $extraContext = '';

        // ── Option A: explicit review URLs in agent config ───────────────────
        // Use these first if the operator has pre-configured known review pages
        // (e.g. a Google Maps or Facebook reviews URL).
        if (! empty($config['review_urls']) && $this->hasTool('scrape_page')) {
            $scraped = [];
            foreach ((array) $config['review_urls'] as $reviewUrl) {
                $content = $this->useTool('scrape_page', ['url' => $reviewUrl]);
                if ($content && $content !== 'Scraping not available for this page.') {
                    $scraped[] = "--- Ревюта от: {$reviewUrl} ---\n{$content}";
                }
            }
            if ($scraped) {
                $extraContext = "\n\n".implode("\n\n", $scraped);
            }
        }

        // ── Option B: Brave search for reviews when we know the target site ──
        // The deep_researcher already ran ONE review search and its results are
        // available in {{input}} (context from previous agents). Run an additional
        // targeted search only if we have a target URL and the Brave tool.
        if ($extraContext === '' && $this->hasTool('web_search')) {
            $targetUrl = $context['target_url'] ?? $context['url'] ?? null;
            if (! empty($targetUrl)) {
                $domain      = strtolower(preg_replace('/^www\./i', '', parse_url($targetUrl, PHP_URL_HOST) ?? ''));
                $reviewQuery = "\"{$domain}\" отзиви OR ревюта OR reviews site:google.com OR site:facebook.com OR site:tripadvisor.com";
                $results     = $this->useTool('web_search', ['query' => $reviewQuery]);
                if ($results) {
                    $extraContext = "\n\n--- Резултати от търсене на ревюта за {$domain} ---\n{$results}";
                }
            }
        }

        // ── Build the analysis instruction (always in Bulgarian) ─────────────
        $instruction = $extraContext !== ''
            ? "\n\nАнализирай ревютата по-горе и предостави на БЪЛГАРСКИ:\n"
              ."- Общ тон и sentiment (позитивен / неутрален / негативен)\n"
              ."- Повтарящи се теми (похвали и оплаквания)\n"
              ."- Конкретни цитати с URL-източник (ако са налични)\n"
              ."- Средна оценка (ако е посочена)\n"
              ."- Кратко заключение\n"
              ."Ако не са намерени реални ревюта — кажи ясно: 'Не са намерени публични ревюта.'\n"
              ."НИКОГА не измисляй ревюта, оценки или коментари."
            : "\n\nПроанализирай налични отзиви от предоставения контекст по-долу.\n"
              ."Ако не са налични реални ревюта — напиши ясно: 'Не са намерени публични ревюта за този бизнес.'\n"
              ."НИКОГА не измисляй ревюта, оценки или коментари.\n"
              ."Отговаряй САМО на БЪЛГАРСКИ.";

        return $this->chat($agent, $agentRun->input, $extraContext.$instruction);
    }
}
