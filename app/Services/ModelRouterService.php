<?php

namespace App\Services;

use App\Models\AgentGenerationLog;
use App\Models\Flow;
use App\Models\NodeRun;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use App\Support\ModelLevel;
use App\Support\PaidModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Task-aware model router — единственото място, което превръща „този агент,
 * това ниво" в конкретен модел pin (и за генерацията, и за смяната на ниво
 * от builder-а).
 *
 * Всеки агент получава ПРОФИЛ от фасетни тежести (какво изисква задачата му):
 * детерминистично от тип/tools/конфиг/граф, а в smart режим (MODEL_ROUTING)
 * безплатен LLM чете ролята и промпта и прецизира тежестите + дава причина.
 * Профилът се скорира срещу capability матрицата (config/model_router.php),
 * с цена според нивото, spread decay (анти-монопол върху free tier-а), глас
 * на планера и историческо представяне (node_runs.qa_score per провайдър/тип).
 *
 * Кодът налага квотите на нивото; LLM-ът само профилира — никога не решава.
 */
class ModelRouterService
{
    public const FACETS = [
        'research', 'extraction', 'analysis', 'synthesis', 'json_strict',
        'bg_language', 'long_context', 'creative', 'speed',
    ];

    private const FACET_LABELS = [
        'research' => 'обработка на уеб резултати',
        'extraction' => 'извличане на структурирани данни',
        'analysis' => 'аналитично мислене',
        'synthesis' => 'синтез на дълъг текст',
        'json_strict' => 'стриктен JSON',
        'bg_language' => 'български език',
        'long_context' => 'дълъг контекст',
        'creative' => 'креативност',
        'speed' => 'скорост',
    ];

    private const PROVIDER_LABELS = [
        'gemini' => 'Gemini', 'deepseek' => 'DeepSeek', 'qwen' => 'Qwen',
        'xai' => 'Grok', 'openai' => 'OpenAI', 'anthropic' => 'Claude',
    ];

    public function __construct(private ModelSelectorService $modelSelector) {}

    /**
     * Пълното разпределение за един план/граф. Всеки item описва агент:
     *
     * @param  array<int, array{key: string, type: string, name: string, role: string,
     *   prompt: string, tools: array<int, string>, fan_in: int, output_language: string,
     *   num_predict: int, temperature: float, map_reduce: bool, is_verifier: bool,
     *   planner_provider: ?string}>  $items
     * @return array<string, array{model: string, reason: string}> key → pin ('' = локален авто)
     */
    public function assign(array $items, ModelLevel $level, ?Flow $flow = null, ?string $logToken = null): array
    {
        $profiles = [];
        foreach (array_values($items) as $order => $item) {
            $profiles[(string) $item['key']] = $this->profile($item) + ['item' => $item, 'order' => $order];
        }

        if ($profiles !== [] && config('model_router.mode', 'smart') === 'smart') {
            $profiles = $this->enrichWithLlm($profiles, $flow, $logToken);
        }

        $out = [];
        $pinnable = [];
        foreach ($profiles as $key => $p) {
            if ($p['exempt'] === 'vision') {
                $out[$key] = ['model' => '', 'reason' => 'Vision задача → локален мултимодален модел.'];

                continue;
            }
            if ($p['exempt'] === 'bg' && $level->bgStaysLocal()) {
                $out[$key] = ['model' => '', 'reason' => 'Български текст за краен потребител → локален BgGPT.'];

                continue;
            }
            $pinnable[$key] = $p;
        }

        $cheap = ModelLevel::availableCheapProviders();
        $history = $this->historyStats();

        // Колко би спечелил всеки нод от НАЙ-добрия си евтин провайдър (без
        // spread) — ранкингът, който решава кои нодове получават cloud
        // слотовете на low/medium.
        $benefit = [];
        foreach ($pinnable as $key => $p) {
            $benefit[$key] = 0.0;
            foreach ($cheap as $provider) {
                $benefit[$key] = max($benefit[$key], $this->score($p, $provider, $level, $history, 0));
            }
        }

        $keysByBenefit = array_keys($pinnable);
        usort($keysByBenefit, fn ($a, $b) => ($benefit[$b] <=> $benefit[$a]) ?: ($pinnable[$a]['order'] <=> $pinnable[$b]['order']));

        // Cloud слотовете според квотите на нивото.
        $cloudKeys = $keysByBenefit;
        if ($level === ModelLevel::Low) {
            $cloudKeys = array_slice($keysByBenefit, 0, (int) $level->cheapMax());
        } elseif ($level === ModelLevel::Medium) {
            $shortfall = max(0, $level->ollamaMin() - count($out));
            $cloudKeys = array_slice($keysByBenefit, 0, max(0, count($keysByBenefit) - $shortfall));
        }

        // Premium слотовете на high/ultra отиват при най-тежкия СИНТЕЗ (не при
        // голия fan-in) — verifier-ът никога не взима premium слот.
        if ($level === ModelLevel::Ultra) {
            $this->assignUltra($out, $pinnable, $cloudKeys, $level, $cheap);
        } else {
            $premium = $level === ModelLevel::High && PaidModel::available('openai')
                ? array_slice($this->rankBySynthesis($cloudKeys, $pinnable), 0, (int) $level->openaiMax())
                : [];

            foreach ($premium as $key) {
                $out[$key] = [
                    'model' => PaidModel::pin('openai'),
                    'reason' => $this->reason($pinnable[$key], 'openai', 'premium слот за най-критичния синтез'),
                ];
            }

            $this->assignCheap($out, $pinnable, $cloudKeys, $level, $cheap, $history);
        }

        // Останалите pinnable без pin → локални (леки стъпки на low/medium).
        foreach ($pinnable as $key => $p) {
            if (! isset($out[$key])) {
                $out[$key] = ['model' => '', 'reason' => 'Лека стъпка → локален модел (ниво '.$level->label().').'];
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Per-level разпределяне
    // ──────────────────────────────────────────────────────────────────────

    /** @param array<string, array<string, mixed>> $pinnable */
    private function assignUltra(array &$out, array $pinnable, array $cloudKeys, ModelLevel $level, array $cheap): void
    {
        $anthropicSet = PaidModel::available('anthropic')
            ? array_slice($this->rankBySynthesis($cloudKeys, $pinnable), 0, $level->anthropicMax())
            : [];

        foreach ($cloudKeys as $key) {
            if (in_array($key, $anthropicSet, true)) {
                $out[$key] = [
                    'model' => PaidModel::pin('anthropic'),
                    'reason' => $this->reason($pinnable[$key], 'anthropic', 'слот за най-сложния синтез на ниво Ултра'),
                ];
            } elseif (PaidModel::available('openai')) {
                $out[$key] = [
                    'model' => PaidModel::pin('openai'),
                    'reason' => $this->reason($pinnable[$key], 'openai', 'ниво Ултра'),
                ];
            } elseif ($cheap !== []) {
                $out[$key] = [
                    'model' => PaidModel::pin($cheap[0]),
                    'reason' => $this->reason($pinnable[$key], $cheap[0], 'OpenAI не е наличен — евтин fallback'),
                ];
            }
        }
    }

    /**
     * Евтините pin-ове: за всеки нод (по полза, низходящо) се избира
     * провайдърът с най-висок score; spread decay-ят разпределя натоварването.
     *
     * @param  array<string, array<string, mixed>>  $pinnable
     */
    private function assignCheap(array &$out, array $pinnable, array $cloudKeys, ModelLevel $level, array $cheap, array $history): void
    {
        if ($cheap === []) {
            return; // няма евтин API ключ → нодовете остават локални
        }

        $assigned = [];
        foreach ($cloudKeys as $key) {
            if (isset($out[$key])) {
                continue; // premium слот
            }

            $p = $pinnable[$key];
            $best = null;
            $bestScore = -INF;

            foreach ($cheap as $provider) {
                if (! $this->fitsContext($p, $provider)) {
                    continue;
                }
                $score = $this->score($p, $provider, $level, $history, $assigned[$provider] ?? 0);
                if ($score > $bestScore + 1e-9) {
                    $bestScore = $score;
                    $best = $provider;
                }
            }

            // Никой не побира входа → най-големият контекст все пак е по-добър
            // от провал по средата на run-а.
            $best ??= $this->largestContextProvider($cheap);

            $assigned[$best] = ($assigned[$best] ?? 0) + 1;
            $out[$key] = ['model' => PaidModel::pin($best), 'reason' => $this->reason($p, $best)];
        }
    }

    /** Ранкинг за premium слотовете: synthesis тежест, после fan-in, после ред. */
    private function rankBySynthesis(array $keys, array $pinnable): array
    {
        $eligible = array_values(array_filter($keys, fn ($k) => ! $pinnable[$k]['item']['is_verifier']));

        usort($eligible, function ($a, $b) use ($pinnable) {
            $sa = ($pinnable[$a]['facets']['synthesis'] ?? 0) + (PaidModel::isPremium($pinnable[$a]['item']['planner_provider']) ? 3 : 0);
            $sb = ($pinnable[$b]['facets']['synthesis'] ?? 0) + (PaidModel::isPremium($pinnable[$b]['item']['planner_provider']) ? 3 : 0);

            return ($sb <=> $sa)
                ?: ($pinnable[$b]['item']['fan_in'] <=> $pinnable[$a]['item']['fan_in'])
                ?: ($pinnable[$a]['order'] <=> $pinnable[$b]['order']);
        });

        return $eligible;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Профилиране на задачата
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Детерминистичният профил: фасетни тежести от тип + tools + конфиг +
     * място в графа + ключови думи в промпта.
     *
     * @return array{facets: array<string, int>, est_input_tokens: int, exempt: ?string, llm_reason: ?string}
     */
    private function profile(array $item): array
    {
        $type = $item['type'];

        if ($this->modelSelector->isVisionType($type)) {
            return ['facets' => [], 'est_input_tokens' => 0, 'exempt' => 'vision', 'llm_reason' => null];
        }

        $bulgarian = in_array($item['output_language'] ?? 'bg', ['bg', '', null], true);
        $bgWriter = $this->modelSelector->isBgWritingType($type);

        $facets = match ($this->modelSelector->profileForType($type)) {
            'research' => ['research' => 8, 'extraction' => 4, 'long_context' => 3],
            'analysis' => ['analysis' => 8, 'extraction' => 5, 'json_strict' => 3],
            'report' => ['synthesis' => 9, 'long_context' => 5, 'analysis' => 3],
            'bg_writer' => ['synthesis' => 6, 'creative' => 5],
            'qa' => ['json_strict' => 9, 'speed' => 6],
            'utility' => ['speed' => 8, 'json_strict' => 5],
            'translate' => ['speed' => 4],
            'code' => ['analysis' => 6, 'json_strict' => 6],
            'image_prompt' => ['creative' => 8, 'speed' => 4],
            default => ['analysis' => 6, 'extraction' => 4],
        };

        $bump = function (string $facet, int $by) use (&$facets): void {
            $facets[$facet] = min(10, ($facets[$facet] ?? 0) + $by);
        };

        $tools = $item['tools'];
        if (array_intersect($tools, ['web_search', 'pro_search', 'people_search', 'google_reviews'])) {
            $bump('research', 4);
        }
        if (array_intersect($tools, ['crawl_site', 'scrape_page', 'discover_urls'])) {
            $bump('long_context', 5);
            $bump('research', 3);
        }
        if (in_array('extract_document', $tools, true)) {
            $bump('extraction', 4);
        }

        if ($item['map_reduce']) {
            $facets['long_context'] = 10;
        }
        if ($item['num_predict'] < 0 || $item['num_predict'] >= 6000) {
            $bump('synthesis', 3);
        }
        if (($item['temperature'] ?? 0.3) >= 0.6) {
            $bump('creative', 3);
        }
        if ($item['fan_in'] >= 3) {
            $bump('synthesis', 4);
        }
        if ($item['fan_in'] >= 5) {
            $bump('long_context', 2);
        }
        if ($bulgarian) {
            $facets['bg_language'] = max($facets['bg_language'] ?? 0, $bgWriter ? 8 : 3);
        }

        $text = mb_strtolower($item['name'].' '.$item['role'].' '.$item['prompt']);
        if (preg_match('/json|таблиц|формат/u', $text)) {
            $bump('json_strict', 2);
        }
        if (preg_match('/цен|извлеч|екстрах/u', $text)) {
            $bump('extraction', 2);
        }
        if (preg_match('/анализ|сравн|swot/u', $text)) {
            $bump('analysis', 2);
        }
        if (preg_match('/доклад|обобщ|резюме|синтез/u', $text)) {
            $bump('synthesis', 2);
        }

        $estIn = 6000;
        if (in_array('crawl_site', $tools, true)) {
            $estIn = 60000;
        } elseif (in_array('scrape_page', $tools, true)) {
            $estIn = 20000;
        }
        if ($item['map_reduce']) {
            $estIn = max($estIn, 30000);
        }
        $estIn += 4000 * max(0, $item['fan_in'] - 1);

        return [
            'facets' => $facets,
            'est_input_tokens' => $estIn,
            'exempt' => $bulgarian && $bgWriter ? 'bg' : null,
            'llm_reason' => null,
        ];
    }

    /**
     * Smart режим: безплатен LLM „оглежда" всеки агент (роля + промпт + tools)
     * и връща прецизирани фасетни тежести + причина на български. Една batched
     * structured-output заявка; провал → тих fallback към детерминистичния
     * профил. При генерация се логва като фаза model_routing (с cost).
     *
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array<string, array<string, mixed>>
     */
    private function enrichWithLlm(array $profiles, ?Flow $flow, ?string $logToken): array
    {
        $provider = (string) config('model_router.router_provider', 'gemini');
        if ($provider === 'anthropic' || empty(config("services.{$provider}.api_key"))) {
            return $profiles;
        }
        $model = (string) (config('model_router.router_model') ?: config("services.{$provider}.model"));

        $list = [];
        foreach ($profiles as $key => $p) {
            if ($p['exempt'] === 'vision') {
                continue;
            }
            $list[] = [
                'key' => (string) $key,
                'name' => $p['item']['name'],
                'role' => mb_substr($p['item']['role'], 0, 300),
                'prompt' => mb_substr($p['item']['prompt'], 0, 500),
                'tools' => $p['item']['tools'],
                'inputs' => $p['item']['fan_in'],
            ];
        }
        if ($list === []) {
            return $profiles;
        }

        $system = 'Ти си рутер на AI модели в multi-agent pipeline. За ВСЕКИ агент прецени каква е '
            .'реалната му задача и я оцени по фасети с тежест 0-10 (0 = няма нужда, 10 = критично): '
            .'research (обработка на уеб/tool резултати), extraction (структурирани данни от суров текст), '
            .'analysis (разсъждение и изводи), synthesis (обединяване на много входове в дълъг текст), '
            .'json_strict (строго форматиран изход), bg_language (качествен български за краен потребител), '
            .'long_context (голям входен обем), creative (креативно писане), speed (лека/бърза стъпка). '
            .'reason: 1 кратко изречение на български какво изисква задачата. Върни запис за ВСЕКИ key.';
        $user = json_encode(['agents' => $list], JSON_UNESCAPED_UNICODE);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['agents'],
            'properties' => [
                'agents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'facets', 'reason'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'facets' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => self::FACETS,
                                'properties' => array_fill_keys(self::FACETS, ['type' => 'integer']),
                            ],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $log = $flow ? AgentGenerationLog::create([
            'flow_id' => $flow->id,
            'company_id' => $flow->company_id ?? $flow->company?->id,
            'token' => $logToken,
            'provider' => $provider.' (model_routing)',
            'model' => $model,
            'system_prompt' => $system,
            'user_message' => $user,
            'options' => [],
            'status' => 'running',
        ]) : null;
        $startMs = (int) (microtime(true) * 1000);

        try {
            LlmContext::set([
                'purpose' => 'model_routing',
                'session_id' => $logToken,
                'company_id' => $flow?->company_id,
                'flow_id' => $flow?->id,
            ]);
            $result = OpenAiChatService::for($provider)->chatJson($model, $system, $user, 'model_routing', $schema, [
                'temperature' => 0.1,
                'num_predict' => 4000,
            ]);
            LlmContext::clear();

            $log?->update(array_merge(LlmUsage::take(), [
                'raw_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'parsed_count' => is_array($result['agents'] ?? null) ? count($result['agents']) : null,
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                'status' => 'completed',
            ]));
        } catch (Throwable $e) {
            LlmContext::clear();
            $log?->update(array_merge(LlmUsage::take(), [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            ]));
            Log::warning('[ModelRouter] Smart профилирането се провали — детерминистичната матрица поема: '.$e->getMessage());

            return $profiles;
        }

        foreach ((array) ($result['agents'] ?? []) as $row) {
            $key = (string) ($row['key'] ?? '');
            if (! isset($profiles[$key]) || ! is_array($row['facets'] ?? null)) {
                continue;
            }

            // LLM-ът дава само тежестите + причината; est_input_tokens и
            // изключенията (BG/vision) остават детерминистични.
            $facets = [];
            foreach (self::FACETS as $facet) {
                $value = max(0, min(10, (int) ($row['facets'][$facet] ?? 0)));
                if ($value > 0) {
                    $facets[$facet] = $value;
                }
            }
            if ($facets !== []) {
                $profiles[$key]['facets'] = $facets;
            }
            $profiles[$key]['llm_reason'] = trim((string) ($row['reason'] ?? '')) ?: null;
        }

        return $profiles;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Скоринг
    // ──────────────────────────────────────────────────────────────────────

    private function score(array $p, string $provider, ModelLevel $level, array $history, int $alreadyAssigned): float
    {
        $providerFacets = (array) config("model_router.providers.{$provider}.facets", []);

        $score = 0.0;
        foreach ($p['facets'] as $facet => $weight) {
            $score += $weight * (float) ($providerFacets[$facet] ?? 5) / 10;
        }

        // Цена: USD за един run на нода (est input/output) × penalty на нивото.
        $penalty = (float) (config('model_router.cost_penalty')[$level->value] ?? 1.0);
        $outTokens = $p['item']['num_predict'] > 0 ? $p['item']['num_predict'] : 3000;
        $runtime = PaidModel::strip(PaidModel::pin($provider));
        $score -= $penalty * 1000 * LlmUsage::costFor($provider, $runtime, $p['est_input_tokens'], $outTokens);

        $score -= (float) config('model_router.spread_decay', 5.0) * $alreadyAssigned;

        if (($p['item']['planner_provider'] ?? null) === $provider) {
            $score += (float) config('model_router.planner_vote_bonus', 8.0);
        }

        return $score + $this->historyBonus($history, $provider, $p['item']['type']);
    }

    private function fitsContext(array $p, string $provider): bool
    {
        $ctx = (int) config("model_router.providers.{$provider}.ctx", 128_000);

        return $p['est_input_tokens'] <= (int) ($ctx * 0.75);
    }

    private function largestContextProvider(array $providers): string
    {
        usort($providers, fn ($a, $b) => (int) config("model_router.providers.{$b}.ctx", 0) <=> (int) config("model_router.providers.{$a}.ctx", 0));

        return $providers[0];
    }

    /** Човешка причина за избора: какво изисква задачата → кой я покрива. */
    private function reason(array $p, string $provider, ?string $slotNote = null): string
    {
        $facets = $p['facets'];
        arsort($facets);
        $top = array_slice(array_keys($facets), 0, 2);
        $labels = implode(', ', array_map(fn ($f) => self::FACET_LABELS[$f] ?? $f, $top));

        $head = $p['llm_reason'] ?: ('Задача: '.$labels.'.');
        $tail = self::PROVIDER_LABELS[$provider] ?? $provider;
        $tail .= $slotNote !== null ? ' — '.$slotNote : ' (силен в: '.$labels.')';

        return rtrim($head, '.').' → '.$tail.'.';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Историческо учене (node_runs.qa_score)
    // ──────────────────────────────────────────────────────────────────────

    /** @return array{pair: array<string, array<string, float|int>>, type: array<string, array<string, float|int>>} */
    private function historyStats(): array
    {
        $days = (int) config('model_router.history_days', 30);

        return Cache::remember('model_router_history_v1', 600, function () use ($days) {
            $rows = NodeRun::query()
                ->join('flow_nodes', 'flow_nodes.id', '=', 'node_runs.flow_node_id')
                ->where('node_runs.created_at', '>=', now()->subDays($days))
                ->whereNotNull('node_runs.model_used')
                ->whereIn('node_runs.status', ['completed', 'failed'])
                ->get([
                    'flow_nodes.type as type',
                    'node_runs.model_used as model_used',
                    'node_runs.status as status',
                    'node_runs.qa_score as qa_score',
                ]);

            $stats = ['pair' => [], 'type' => []];
            foreach ($rows as $row) {
                $provider = PaidModel::provider($row->model_used);
                if ($provider === null) {
                    continue;
                }

                $pair = $provider.'|'.$row->type;
                $stats['pair'][$pair]['n'] = ($stats['pair'][$pair]['n'] ?? 0) + 1;
                $stats['pair'][$pair]['fails'] = ($stats['pair'][$pair]['fails'] ?? 0) + ($row->status === 'failed' ? 1 : 0);

                if ($row->qa_score !== null) {
                    $stats['pair'][$pair]['qa_sum'] = ($stats['pair'][$pair]['qa_sum'] ?? 0) + (int) $row->qa_score;
                    $stats['pair'][$pair]['qa_n'] = ($stats['pair'][$pair]['qa_n'] ?? 0) + 1;
                    $stats['type'][$row->type]['qa_sum'] = ($stats['type'][$row->type]['qa_sum'] ?? 0) + (int) $row->qa_score;
                    $stats['type'][$row->type]['qa_n'] = ($stats['type'][$row->type]['qa_n'] ?? 0) + 1;
                }
            }

            return $stats;
        });
    }

    /** Бонус/малус по реалното представяне на провайдъра за този тип агент. */
    private function historyBonus(array $stats, string $provider, string $type): float
    {
        $pair = $stats['pair'][$provider.'|'.$type] ?? null;
        if ($pair === null || ($pair['n'] ?? 0) < 3) {
            return 0.0; // твърде малко данни → неутрално
        }

        $bonus = 0.0;

        if (($pair['qa_n'] ?? 0) >= 3 && ($stats['type'][$type]['qa_n'] ?? 0) >= 3) {
            $avg = $pair['qa_sum'] / $pair['qa_n'];
            $typeAvg = $stats['type'][$type]['qa_sum'] / $stats['type'][$type]['qa_n'];
            $bonus += max(-15.0, min(15.0, $avg - $typeAvg)) * (float) config('model_router.history_weight', 0.5);
        }

        $failRate = $pair['fails'] / max(1, $pair['n']);

        return $bonus - $failRate * (float) config('model_router.history_fail_penalty', 10.0);
    }
}
