<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FlowDraft;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * C2 — детерминистични въпросни скриптове за топ-сферите. Въпросите идват от
 * `config/wizard_scripts.php` (фиксиран ред, готови опции, БЕЗ LLM); само
 * `dynamic: 'topic'` въпрос вика ЕДИН евтин LLM call за предложения от знанието.
 * Връща СЪЩИЯ контракт като ClientFlowWizardService::turn(), за да не се пипат
 * job-ът и фронтендът. Непозната заявка → визардът пада на пълния LLM режим.
 */
class WizardScriptService
{
    public function __construct(
        private GeneratorService $generator,
        private KnowledgeService $knowledge,
    ) {}

    /** Разпознай сфера по ключови думи в първото съобщение, или null. */
    public function detect(string $message): ?string
    {
        $text = mb_strtolower(trim($message));
        if ($text === '') {
            return null;
        }

        foreach ((array) config('wizard_scripts.domains', []) as $domain => $config) {
            foreach ((array) ($config['match'] ?? []) as $needle) {
                if (str_contains($text, mb_strtolower((string) $needle))) {
                    return $domain;
                }
            }
        }

        return null;
    }

    /** Стартирай скрипт за сфера → поздрав + първи въпрос. */
    public function start(FlowDraft $draft, string $domain): array
    {
        $config = $this->config($domain);

        $draft->update([
            'script' => ['domain' => $domain, 'step' => 0, 'mode' => 'script'],
            'title' => $draft->title ?: $config['title'],
        ]);

        $first = $config['questions'][0];

        return $this->render($draft, $config, $first,
            reply: 'Чудесно — да направим «'.$config['title'].'». '.$first['text']);
    }

    /** Следваща стъпка: отговорът вече е записан в answers от контролера. */
    public function nextStep(FlowDraft $draft): array
    {
        $script = (array) $draft->script;
        $config = $this->config($script['domain'] ?? '');
        $questions = $config['questions'] ?? [];
        $next = (int) ($script['step'] ?? 0) + 1;

        if ($next >= count($questions)) {
            return $this->assemble($draft, $config);
        }

        $draft->update(['script' => [...$script, 'step' => $next]]);
        $q = $questions[$next];

        return $this->render($draft, $config, $q, reply: $q['text']);
    }

    /**
     * C5: върни скрипта на въпроса с ключ $key — изтрий неговия и по-късните
     * отговори, нагласи step, и задади въпроса наново (детерминистично).
     */
    public function goToStep(FlowDraft $draft, string $key): array
    {
        $script = (array) $draft->script;
        $config = $this->config($script['domain'] ?? '');
        $questions = $config['questions'] ?? [];

        $idx = 0;
        foreach ($questions as $i => $q) {
            if (($q['key'] ?? null) === $key) {
                $idx = $i;
                break;
            }
        }

        // Изтрий отговорите от този въпрос нататък (по-късните зависят от него).
        $answers = (array) $draft->answers;
        foreach (array_slice($questions, $idx) as $q) {
            unset($answers[$q['key']]);
        }

        $draft->update([
            'answers' => $answers,
            'status' => 'interviewing',
            'script' => [...$script, 'step' => $idx],
        ]);

        return $this->render($draft->fresh(), $config, $questions[$idx], reply: 'Нека уточним: '.$questions[$idx]['text']);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function render(FlowDraft $draft, array $config, array $q, string $reply): array
    {
        $options = $q['options'] ?? [];
        if (($q['dynamic'] ?? null) === 'topic') {
            $options = $this->topicOptions($draft->company, $config) ?: $options;
        }

        return [
            'phase' => 'interview',
            'reply' => $reply,
            'question' => [
                'key' => $q['key'],
                'text' => $q['text'],
                'input_type' => in_array($q['input_type'] ?? '', ['radio', 'checkbox'], true) ? $q['input_type'] : 'radio',
                'options' => array_values($options),
                'allow_other' => (bool) ($q['allow_other'] ?? true),
                'other_label' => 'Друго (опиши)',
            ],
            'description_draft' => $this->buildDescription($draft, $config),
            'recap' => $this->recap($draft, $config),
            'suggested_title' => $draft->title ?: $config['title'],
            'progress' => $this->progressFor($draft, $config),
            'cost_usd' => null,
        ];
    }

    private function assemble(FlowDraft $draft, array $config): array
    {
        $description = $this->buildDescription($draft, $config);
        $title = $draft->title ?: $config['title'];

        $draft->update(['status' => 'ready', 'description' => $description, 'title' => $title]);

        $total = count($config['questions'] ?? []);

        return [
            'phase' => 'ready',
            'reply' => 'Готово! Прегледай описанието вдясно — можеш да го редактираш или направо да генерираш.',
            'question' => null,
            'description_draft' => $description,
            'recap' => $this->recap($draft, $config),
            'suggested_title' => $title,
            'progress' => ['index' => $total, 'total' => $total],
            'cost_usd' => null,
        ];
    }

    /** C6: детерминистичен прогрес — скриптът има известна дължина. */
    private function progressFor(FlowDraft $draft, array $config): array
    {
        $total = count($config['questions'] ?? []);
        $step = (int) data_get($draft->script, 'step', 0);

        return ['index' => max(1, min($step + 1, $total)), 'total' => $total];
    }

    /** Попълва description_template с наличните отговори; чисти непопълнените. */
    private function buildDescription(FlowDraft $draft, array $config): string
    {
        $answers = (array) $draft->answers;
        $tokens = ['{company}' => (string) ($draft->company?->name ?? 'фирмата')];

        foreach (($config['questions'] ?? []) as $q) {
            $key = $q['key'];
            $tokens['{'.$key.'}'] = $this->answerText($config, $key, (array) ($answers[$key] ?? []));
        }

        $desc = strtr($config['description_template'] ?? '', $tokens);
        // Махни остатъчни плейсхолдъри + двойни интервали.
        $desc = preg_replace('/\{[a-z_]+\}/u', '', (string) $desc);

        return trim(preg_replace('/\s{2,}/u', ' ', (string) $desc));
    }

    /** Стойностите на отговора → човешки етикети (статичните чрез опциите). */
    private function answerText(array $config, string $key, array $values): string
    {
        $q = collect($config['questions'] ?? [])->firstWhere('key', $key) ?? [];
        $labels = collect($q['options'] ?? [])->pluck('label', 'value');

        return collect($values)
            ->map(fn ($v) => (string) ($labels[$v] ?? $v)) // dynamic/topic + „Друго": стойността Е етикетът
            ->filter(fn ($v) => trim($v) !== '')
            ->implode(', ');
    }

    /** @return list<string> кратко обобщение „въпрос: отговор". */
    private function recap(FlowDraft $draft, array $config): array
    {
        $answers = (array) $draft->answers;

        return collect($config['questions'] ?? [])
            ->filter(fn ($q) => ! empty($answers[$q['key']] ?? []))
            ->map(fn ($q) => rtrim($q['text'], '?').': '.$this->answerText($config, $q['key'], (array) $answers[$q['key']]))
            ->values()
            ->all();
    }

    /**
     * 3–5 теми, заземени във фирменото знание — ЕДИН евтин LLM call (без agentic
     * loop). При провал/празно → []; въпросът остава с allow_other (клиентът пише).
     *
     * @return list<array{value: string, label: string}>
     */
    private function topicOptions(?Company $company, array $config): array
    {
        if (! $company) {
            return [];
        }

        $kb = $this->knowledgeSummary($company);

        $system = 'Ти предлагаш 3–5 КОНКРЕТНИ, кратки теми (на български) за «'.$config['title']
            .'», свързани с реалната дейност на фирмата. Без обяснения — само JSON по схемата.';
        $user = "ФИРМА: {$company->name}".($company->industry ? " ({$company->industry})" : '')."\n"
            .($kb !== '' ? "ЗНАНИЕ ЗА ФИРМАТА:\n{$kb}\n" : '')
            .'Предложи 3–5 различни теми.';

        try {
            $result = $this->generator->chatJson(
                $system, $user,
                (string) config('wizard_scripts.topic_phase', 'eval_judge'),
                $this->topicSchema(),
                ['temperature' => 0.6, 'num_predict' => 600],
            );

            return collect($result['topics'] ?? [])
                ->map(fn ($t) => trim((string) (is_array($t) ? ($t['label'] ?? '') : $t)))
                ->filter(fn ($l) => $l !== '')
                ->take(5)
                ->map(fn ($l) => ['value' => $l, 'label' => $l]) // value=label → директно използваемо в описанието
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('[WizardScript] topicOptions failed: '.$e->getMessage());

            return [];
        }
    }

    private function knowledgeSummary(Company $company): string
    {
        if (! KnowledgeService::enabled($company) || $this->knowledge->isEmpty($company)) {
            return '';
        }

        try {
            $summary = $this->knowledge->summary($company);
            $parts = [];
            if (! empty($summary['titles'])) {
                $parts[] = 'Материали: '.implode(', ', array_slice($summary['titles'], 0, 8)).'.';
            }
            $profile = $this->knowledge->ownProfileBlock($company);
            if ($profile !== '') {
                $parts[] = mb_substr($profile, 0, 1200);
            }

            return implode("\n", $parts);
        } catch (Throwable) {
            return '';
        }
    }

    private function topicSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['label' => ['type' => 'string']],
                        'required' => ['label'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['topics'],
            'additionalProperties' => false,
        ];
    }

    private function config(string $domain): array
    {
        return (array) config("wizard_scripts.domains.{$domain}", []);
    }
}
