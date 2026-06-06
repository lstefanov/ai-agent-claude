<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

/**
 * Analyses ONLY verified customer reviews. Fabrication is prevented structurally,
 * not by prompt wording (small models ignore "don't make things up" — run 71
 * invented 5 fake Google reviews with broken Bulgarian):
 *
 *  - Trusted sources = Google Places API (rating + count + sample reviews) and
 *    operator-configured review_urls. Only these are fed to the model.
 *  - Web-search snippets / Google-scrape are NOT used as review text — they were
 *    the fuel for hallucinated ratings and quotes.
 *  - If no trusted source returns data, we emit a deterministic "no verified
 *    reviews" statement WITHOUT any LLM generation.
 *
 * The Places query uses the real business name + city from the site_context
 * profile (this agent's input), so it matches the correct place instead of a
 * domain-derived guess.
 */
class ReviewAnalyzerAgent extends BaseAgent
{
    private const BG_CITIES = 'София|Пловдив|Варна|Бургас|Русе|Стара Загора|Плевен|Сливен|Добрич|Шумен|Перник|Хасково|Ямбол|Пазарджик|Благоевград|Велико Търново|Враца|Габрово|Видин|Монтана|Кърджали|Кюстендил|Търговище|Ловеч|Силистра|Разград|Смолян|Асеновград|Дупница|Казанлък|Свищов';

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config = $agent->config ?? [];
        $targetUrl = $context['target_url'] ?? $context['url'] ?? null;
        $domain = $targetUrl
            ? strtolower(preg_replace('/^www\./i', '', parse_url($targetUrl, PHP_URL_HOST) ?? ''))
            : '';

        [$businessName, $city] = $this->businessIdentity($agentRun->input, $domain);
        $region = ($domain !== '' && preg_match('/\.([a-z]{2})$/i', $domain, $m)) ? strtoupper($m[1]) : null;

        $verified = [];

        // ── 1. Google Places API — the trusted source for public reviews ──
        if ($businessName !== '' && $this->hasTool('google_reviews')) {
            $query = trim($businessName.' '.$city);
            $places = $this->useTool('google_reviews', ['query' => $query, 'region' => $region]);
            if ($places !== null && trim($places) !== '') {
                $verified[] = "--- Google ревюта (Places API) за: {$query} ---\n{$places}";
            }
        }

        // ── 2. Operator-configured review URLs — a deliberate, trusted signal ──
        if (! empty($config['review_urls']) && $this->hasTool('scrape_page')) {
            foreach ((array) $config['review_urls'] as $pageUrl) {
                $content = $this->useTool('scrape_page', ['url' => $pageUrl]);
                if ($content && $content !== 'Scraping not available for this page.') {
                    $verified[] = "--- Ревюта от {$pageUrl} (зададена от оператора) ---\n".mb_substr($content, 0, 8000);
                }
            }
        }

        // No trusted source → deterministic statement. We never let the model
        // synthesise ratings/quotes from web snippets.
        if (empty($verified)) {
            return 'Не са намерени проверени публични ревюта за този бизнес'
                .($businessName !== '' ? ' („'.$businessName.'")' : '')
                .'. Google Places не върна резултат и няма зададен от оператора източник на ревюта, '
                .'затова не се представят клиентски оценки или цитати.';
        }

        $extraContext = "\n\n".implode("\n\n", $verified);

        $instruction = "\n\nАнализирай САМО реалните ревюта от източниците по-горе и дай на БЪЛГАРСКИ:\n"
            ."- Общ тон и sentiment (позитивен / неутрален / негативен)\n"
            ."- Средна оценка и брой ревюта (точно както са посочени в данните)\n"
            ."- Повтарящи се похвали и оплаквания\n"
            ."- Конкретни цитати С ИЗТОЧНИК — дословно от текста по-горе\n"
            ."- Кратко заключение\n"
            .'Използвай ЕДИНСТВЕНО данните по-горе. НИКОГА не измисляй ревюта, оценки, имена или цитати.';

        return $this->chat($agent, $agentRun->input, $extraContext.$instruction);
    }

    /**
     * Pull the business name + city out of the site_context profile so the Places
     * lookup matches the real place. Falls back to a domain-derived name.
     *
     * @return array{0: string, 1: string}
     */
    private function businessIdentity(string $profile, string $domain): array
    {
        $name = '';
        if (preg_match('/(?:име(?:то)?\s*(?:на\s*бизнеса|на\s*марката)?|марка|бранд)[^:：\n]*[:：]\s*(.+)/iu', $profile, $m)) {
            $line = trim((string) preg_split('/[\n\r|]/u', $m[1])[0]);
            $name = trim(preg_replace('/[*_`#"„"]+/u', '', $line));
            $name = mb_substr($name, 0, 60);
        }
        if ($name === '' && $domain !== '') {
            $name = ucfirst(preg_replace('/\..*$/', '', $domain));
        }

        $city = '';
        if (preg_match('/\b('.self::BG_CITIES.')\b/u', $profile, $m)) {
            $city = $m[1];
        }

        return [$name, $city];
    }
}
