<?php

namespace App\Services;

use App\Models\FlowEvalCase;
use App\Models\FlowEvalRun;
use App\Models\FlowRun;
use App\Models\FlowVersion;
use App\Support\ModelLevel;
use Throwable;

/**
 * Eval Suite ядро. Пуска ИСТИНСКИ FlowRun за един golden case на конкретна
 * версия × ниво (с run-scoped model override за нивото), а след като run-ът
 * приключи — оценява изхода (детерминистични правила + LLM-as-judge) и
 * агрегира претеглен score 0–100.
 *
 * Изпълнението е event-driven: runCase() само подготвя и диспечва (не чака).
 * GraphFlowExecutor::finalize() вижда context['eval_run_id'] и диспечва
 * JudgeEvalRunJob → judgeRun().
 */
class EvalRunnerService
{
    public function __construct(
        private GraphFlowExecutor $executor,
        private GeneratorService $generator,
        private AgentGeneratorService $agentGenerator,
        private GraphNormalizer $normalizer,
    ) {}

    /**
     * Подготвя eval FlowRun за нивото и го диспечва. НЕ чака завършване —
     * judge-ването става след finalize() през JudgeEvalRunJob.
     */
    public function runCase(FlowEvalCase $case, FlowVersion $version, ModelLevel $level, ?string $sessionToken = null): FlowEvalRun
    {
        $flow = $version->flow ?? $case->flow;

        // 1. Моделите за ЦЕЛЕВОТО ниво (както relevel) → run-scoped override карта.
        [$nodes, $edges] = $this->normalizer->parse((array) ($version->graph_layout ?? []));
        $assignments = $this->agentGenerator->assignModelsForLevel($nodes, $edges, $level);
        $overrides = [];
        foreach ($assignments as $key => $assignment) {
            $overrides[(string) $key] = (string) ($assignment['model'] ?? ''); // '' = локален авто
        }

        // 2. Eval run редът (за да имаме id за context на FlowRun-а).
        $evalRun = FlowEvalRun::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'eval_case_id' => $case->id,
            'model_level' => $level->value,
            'session_token' => $sessionToken,
            'status' => 'running',
        ]);

        // 3. Истински FlowRun, пинат към версията, с eval контекста.
        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'status' => 'pending',
            'triggered_by' => 'eval',
            'context' => [
                'inputs' => $this->buildInputs((array) ($case->input_data ?? [])),
                'eval_run_id' => $evalRun->id,
                'eval_level' => $level->value,
                'model_overrides' => $overrides,
            ],
        ]);

        $evalRun->update(['flow_run_id' => $flowRun->id]);

        // 4. Диспечва вълните (на flows опашката) и излиза.
        $this->executor->run($flow, 'eval', $flowRun);

        return $evalRun;
    }

    /**
     * Оценява завършилия FlowRun на този eval run: правила + judge → агрегиран
     * score, цена (sum node_runs.cost_usd), времетраене.
     */
    public function judgeRun(FlowEvalRun $evalRun): void
    {
        $evalRun->loadMissing('evalCase', 'flowRun');
        $case = $evalRun->evalCase;
        $flowRun = $evalRun->flowRun;

        if (! $case || ! $flowRun) {
            $evalRun->update(['status' => 'failed', 'error' => 'Липсва eval case или flow run.']);

            return;
        }

        if ($flowRun->status !== 'completed') {
            $evalRun->update([
                'status' => 'failed',
                'error' => 'FlowRun не завърши успешно ('.$flowRun->status.').',
            ]);

            return;
        }

        $output = (string) ($flowRun->final_output ?? '');

        $rules = $this->evaluateRules($case, $output);
        $judged = $this->judge($case, $output);
        $detail = $rules + $judged['detail'];

        $duration = ($flowRun->started_at && $flowRun->completed_at)
            ? $flowRun->started_at->diffInMilliseconds($flowRun->completed_at)
            : null;

        $evalRun->update([
            'status' => 'completed',
            'score' => round($this->aggregate($case, $detail), 2),
            'scores_detail' => $detail,
            'judge_log' => $judged['log'],
            'cost_usd' => round((float) $flowRun->nodeRuns()->sum('cost_usd'), 6),
            'duration_ms' => $duration,
            'final_output' => $output,
            'error' => null,
        ]);
    }

    /**
     * LLM-as-judge за llm_judge критериите.
     *
     * @return array{detail: array<string, array{score: int, reason: string}>, log: array<string, mixed>}
     */
    public function judge(FlowEvalCase $case, string $output): array
    {
        $detail = [];
        $log = [];

        foreach ((array) $case->criteria as $criterion) {
            if ((string) ($criterion['type'] ?? 'llm_judge') !== 'llm_judge') {
                continue;
            }
            $key = (string) ($criterion['key'] ?? '');
            if ($key === '') {
                continue;
            }

            try {
                $res = $this->generator->chatJson(
                    $this->judgeSystemPrompt(),
                    $this->judgeUserMessage($case, $criterion, $output),
                    'eval_judge',
                    $this->judgeSchema(),
                    ['temperature' => 0.0],
                );
                $detail[$key] = [
                    'score' => max(0, min(100, (int) ($res['score'] ?? 0))),
                    'reason' => (string) ($res['reason'] ?? ''),
                ];
                $log[$key] = $res;
            } catch (Throwable $e) {
                $detail[$key] = ['score' => 0, 'reason' => 'Грешка при оценяване: '.$e->getMessage()];
                $log[$key] = ['error' => $e->getMessage()];
            }
        }

        return ['detail' => $detail, 'log' => $log];
    }

    /**
     * Детерминистични критерии (rule / regex) — без LLM.
     *
     * @return array<string, array{score: int, reason: string}>
     */
    public function evaluateRules(FlowEvalCase $case, string $output): array
    {
        $detail = [];

        foreach ((array) $case->criteria as $criterion) {
            $type = (string) ($criterion['type'] ?? 'llm_judge');
            if ($type === 'llm_judge') {
                continue;
            }
            $key = (string) ($criterion['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $detail[$key] = match ($type) {
                'rule' => $this->applyRule($criterion, $output),
                'regex' => $this->applyRegex($criterion, $output),
                default => ['score' => 0, 'reason' => 'Непознат тип критерий: '.$type],
            };
        }

        return $detail;
    }

    /**
     * Среднопретеглен score 0–100 от всички оценени критерии (тежест по criterion).
     *
     * @param  array<string, array{score: int|float, reason: string}>  $detail
     */
    public function aggregate(FlowEvalCase $case, array $detail): float
    {
        $sum = 0.0;
        $totalWeight = 0.0;

        foreach ((array) $case->criteria as $criterion) {
            $key = (string) ($criterion['key'] ?? '');
            if ($key === '' || ! isset($detail[$key])) {
                continue;
            }
            $weight = isset($criterion['weight']) ? (float) $criterion['weight'] : 1.0;
            $sum += ((float) ($detail[$key]['score'] ?? 0)) * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $sum / $totalWeight : 0.0;
    }

    /**
     * Авто-препоръка на оптимизатора: максималното съотношение score/цена
     * (ефективната граница на Парето) + сравнения с по-качествените точки.
     *
     * @param  array<int, array{label: string, version_id: int, version_name: string, level: string, score: float|null, cost: float}>  $points
     * @return array{best: array<string, mixed>, comparisons: array<int, array<string, mixed>>}|null
     */
    public function recommend(array $points): ?array
    {
        $valid = array_values(array_filter($points, fn ($p) => ($p['score'] ?? null) !== null));
        if ($valid === []) {
            return null;
        }

        foreach ($valid as &$point) {
            $cost = (float) ($point['cost'] ?? 0);
            // Безплатна (само локални модели) точка → максимална ефективност.
            $point['efficiency'] = $cost > 0 ? ($point['score'] / $cost) : ($point['score'] * 1e9);
        }
        unset($point);

        usort($valid, fn ($a, $b) => $b['efficiency'] <=> $a['efficiency']);
        $best = $valid[0];

        $comparisons = [];
        foreach ($valid as $point) {
            if (($point['label'] ?? null) === ($best['label'] ?? null)) {
                continue;
            }
            if ((float) ($point['score'] ?? 0) <= (float) ($best['score'] ?? 0)) {
                continue;
            }
            $bestCost = (float) ($best['cost'] ?? 0);
            $comparisons[] = [
                'label' => $point['label'],
                'delta_score' => round((float) $point['score'] - (float) $best['score'], 1),
                'delta_cost_pct' => $bestCost > 0 ? (int) round(((float) $point['cost'] - $bestCost) / $bestCost * 100) : null,
                'cost' => (float) $point['cost'],
            ];
        }

        return ['best' => $best, 'comparisons' => $comparisons];
    }

    /**
     * input_data ({prompt, variables}) → плоски run inputs, мапнати върху
     * GraphFlowExecutor::buildSeed (topic/input + произволни placeholder-и).
     *
     * @param  array<string, mixed>  $inputData
     * @return array<string, string>
     */
    private function buildInputs(array $inputData): array
    {
        $inputs = [];

        $prompt = trim((string) ($inputData['prompt'] ?? ''));
        if ($prompt !== '') {
            $inputs['topic'] = $prompt;
            $inputs['input'] = $prompt;
        }

        if (! empty($inputData['url'])) {
            $inputs['url'] = (string) $inputData['url'];
        }

        foreach ((array) ($inputData['variables'] ?? []) as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $inputs[$key] = (string) $value;
            }
        }

        return $inputs;
    }

    // ── Rule-based criteria ─────────────────────────────────────────────────

    /** @param  array<string, mixed>  $c @return array{score: int, reason: string} */
    private function applyRule(array $c, string $output): array
    {
        return match ((string) ($c['rule'] ?? '')) {
            'word_count' => $this->ruleWordCount($c, $output),
            'contains_keyword' => $this->ruleContainsKeyword($c, $output),
            'no_placeholder' => $this->ruleNoPlaceholder($output),
            'valid_json' => $this->ruleValidJson($output),
            default => ['score' => 0, 'reason' => 'Непознато правило: '.(string) ($c['rule'] ?? '')],
        };
    }

    /** @param  array<string, mixed>  $c @return array{score: int, reason: string} */
    private function ruleWordCount(array $c, string $output): array
    {
        $words = count(preg_split('/\s+/u', trim($output), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $min = isset($c['min']) ? (int) $c['min'] : null;
        $max = isset($c['max']) ? (int) $c['max'] : null;

        if ($min !== null && $words < $min) {
            return ['score' => $min > 0 ? max(0, (int) round($words / $min * 100)) : 0,
                'reason' => "{$words} думи — под минимума {$min}."];
        }
        if ($max !== null && $words > $max) {
            $over = $words - $max;

            return ['score' => max(0, (int) round(100 - $over / max(1, $max) * 100)),
                'reason' => "{$words} думи — над максимума {$max}."];
        }

        $range = $min !== null || $max !== null ? ' ('.($min ?? 0).'–'.($max ?? '∞').')' : '';

        return ['score' => 100, 'reason' => "{$words} думи — в рамките{$range}."];
    }

    /** @param  array<string, mixed>  $c @return array{score: int, reason: string} */
    private function ruleContainsKeyword(array $c, string $output): array
    {
        $keywords = isset($c['keywords']) && is_array($c['keywords'])
            ? array_map('strval', $c['keywords'])
            : array_filter([(string) ($c['keyword'] ?? '')]);

        if ($keywords === []) {
            return ['score' => 0, 'reason' => 'Не е зададена ключова дума.'];
        }

        $haystack = mb_strtolower($output);
        $missing = array_values(array_filter(
            $keywords,
            fn ($kw) => $kw !== '' && ! str_contains($haystack, mb_strtolower($kw)),
        ));

        if ($missing === []) {
            return ['score' => 100, 'reason' => 'Съдържа: '.implode(', ', $keywords).'.'];
        }

        $found = count($keywords) - count($missing);

        return ['score' => (int) round($found / max(1, count($keywords)) * 100),
            'reason' => 'Липсва: '.implode(', ', $missing).'.'];
    }

    /** @return array{score: int, reason: string} */
    private function ruleNoPlaceholder(string $output): array
    {
        $patterns = [
            // Bracketed ALL-CAPS токени (незапълнени слотове): [ИМЕ], [NAME], [ДАТА],
            // [FIRST NAME]. Не хваща цитати [1] или mixed-case референции [виж].
            '/\[[A-ZА-Я][A-ZА-Я0-9 _\/.-]{1,40}\]/u',
            '/\[[^\]]*(?:placeholder|todo|tbd|вмъкни|попълни|въведете|your )[^\]]*\]/iu',
            '/\{\{[^}]+\}\}/u',
            '/\b(?:lorem ipsum|PLACEHOLDER|TODO|TBD|XXXX?)\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output, $m)) {
                return ['score' => 0, 'reason' => 'Намерен незапълнен шаблон: '.trim($m[0]).'.'];
            }
        }

        return ['score' => 100, 'reason' => 'Няма незапълнени шаблони.'];
    }

    /** @return array{score: int, reason: string} */
    private function ruleValidJson(string $output): array
    {
        $candidate = trim($output);
        // Свали ```json ... ``` ограждане, ако има.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/su', $candidate, $m)) {
            $candidate = trim($m[1]);
        }
        json_decode($candidate, true);

        return json_last_error() === JSON_ERROR_NONE
            ? ['score' => 100, 'reason' => 'Валиден JSON.']
            : ['score' => 0, 'reason' => 'Невалиден JSON: '.json_last_error_msg().'.'];
    }

    /** @param  array<string, mixed>  $c @return array{score: int, reason: string} */
    private function applyRegex(array $c, string $output): array
    {
        $pattern = (string) ($c['pattern'] ?? '');
        if ($pattern === '') {
            return ['score' => 0, 'reason' => 'Не е зададен регулярен израз.'];
        }
        // Обвий с разделители, ако потребителят е въвел гол шаблон.
        if (! preg_match('/^([\/#~]).*\1[a-z]*$/i', $pattern)) {
            $pattern = '/'.str_replace('/', '\/', $pattern).'/u';
        }

        $shouldMatch = (bool) ($c['should_match'] ?? true);
        $matched = @preg_match($pattern, $output) === 1;

        if ($matched === $shouldMatch) {
            return ['score' => 100, 'reason' => $shouldMatch ? 'Форматът съвпада.' : 'Няма нежелан формат.'];
        }

        return ['score' => 0, 'reason' => $shouldMatch ? 'Форматът не съвпада.' : 'Намерен нежелан формат.'];
    }

    // ── LLM-as-judge prompt ─────────────────────────────────────────────────

    private function judgeSystemPrompt(): string
    {
        return <<<'TXT'
Ти си безпристрастен оценител на AI изходи. Оценяваш изхода по ЕДИН критерий от 0 до 100:
- 90–100: изключително добре изпълнен критерий
- 70–89:  добре изпълнен с малки пропуски
- 50–69:  частично изпълнен
- 0–49:   не е изпълнен или е грубо нарушен
Връщаш само валиден JSON: { "score": <число 0-100>, "reason": "<кратко обяснение на български>" }.
TXT;
    }

    /** @param  array<string, mixed>  $criterion */
    private function judgeUserMessage(FlowEvalCase $case, array $criterion, string $output): string
    {
        $input = trim((string) (($case->input_data['prompt'] ?? '')));
        $label = (string) ($criterion['label'] ?? $criterion['key'] ?? '');
        $description = (string) ($criterion['description'] ?? '');

        return "ВХОД (заявка към flow-а):\n{$input}\n\n"
            ."КРИТЕРИЙ: {$label}\n{$description}\n\n"
            ."ИЗХОД ЗА ОЦЕНКА:\n{$output}";
    }

    /** @return array<string, mixed> */
    private function judgeSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'reason'],
            'properties' => [
                'score' => ['type' => 'integer'],
                'reason' => ['type' => 'string'],
            ],
        ];
    }
}
