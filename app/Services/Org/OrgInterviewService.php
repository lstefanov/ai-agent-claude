<?php

namespace App\Services\Org;

use App\Models\BusinessProfile;
use App\Services\GeneratorService;
use App\Support\LlmUsage;
use App\Support\PromptData;

/**
 * Интервюто на Управителя (§structured-sweep + AI depth). Двуфазно:
 *
 * 1) ОБЗОР (без LLM) — един детерминистичен multi-select върху ВСИЧКИ бизнес области
 *    (config organization.interview_areas). Гарантира пълнота; всяка маркирана област =
 *    домейн от department_catalog → става отдел надолу по веригата (composeStructure).
 * 2) ЗАДЪЛБОЧАВАНЕ (LLM) — по ЕДИН уточняващ multi-select въпрос за всяка ИЗБРАНА област,
 *    с текстовете на вече зададените въпроси в промпта → БЕЗ повторения. Спира при покрити
 *    области или max_questions.
 *
 * Кодът ГАРАНТИРА: checkbox (освен същинско Да/Не), стабилни ключове (`areas`, `area_<domain>`,
 * без презапис) и прогреса (обзорът никога не блокира; провал на depth → просто „ready").
 */
class OrgInterviewService
{
    public function __construct(private GeneratorService $generator) {}

    /**
     * Един ход на интервюто.
     *
     * @return array{phase: string, reply: string, question: ?array, recap: array, cost_usd: ?float}
     */
    public function turn(BusinessProfile $profile, string $userInput, ?callable $onStage = null): array
    {
        $answers = (array) $profile->interview_answers;

        // Фаза 1 — обзорът (без LLM): питаме за ВСИЧКИ области наведнъж.
        if (! array_key_exists('areas', $answers)) {
            $onStage && $onStage('Подготвям въпросите…');

            return [
                'phase' => 'interview',
                'reply' => 'За да сглобя точния екип, маркирай ВСИЧКИ области, в които бизнесът има предизвикателства или нужди — спокойно повече от една.',
                'question' => $this->sweepQuestion(),
                'recap' => [],
                'cost_usd' => 0.0,
            ];
        }

        // Фаза 2 — задълбочаване по избраните области (по една на ход, без повторения).
        $maxQuestions = (int) config('organization.manager.max_questions', 10);
        $selected = $this->selectedDomains($answers);
        $nextDomain = $this->nextUncoveredDomain($selected, $answers);

        if ($nextDomain !== null && count($answers) < $maxQuestions) {
            $onStage && $onStage('Обмислям следващия въпрос…');
            [$question, $reply, $cost] = $this->depthQuestion($profile, $nextDomain, $answers);
            if ($question !== null) {
                return ['phase' => 'interview', 'reply' => $reply, 'question' => $question, 'recap' => [], 'cost_usd' => $cost];
            }
            // Depth LLM не върна въпрос → НЕ блокирай онбординга; обзорът вече дава пълнотата.
        }

        // Всичко покрито (или достигнат лимит) → готови.
        return [
            'phase' => 'ready',
            'reply' => 'Имам ясна и пълна представа за нуждите ви. Да сглобим екипа.',
            'question' => null,
            'recap' => $this->recap($selected),
            'cost_usd' => 0.0,
        ];
    }

    // ── Фаза 1: обзор ─────────────────────────────────────────────────────

    /** Детерминистичен multi-select върху всички бизнес области (стабилен ключ `areas`). */
    private function sweepQuestion(): array
    {
        $options = [];
        foreach ($this->interviewAreas() as $domain => $label) {
            $options[] = ['value' => $domain, 'label' => $label];
        }

        return [
            'key' => 'areas',
            'text' => 'В кои от тези области виждаш предизвикателства или нужди за бизнеса си?',
            'input_type' => 'checkbox',
            'options' => $options,
            'allow_other' => true,
            'other_label' => 'Друго (опиши)',
        ];
    }

    /** Областите за обзора: domain => interview_label (от каталога, в конфигурирания ред). */
    private function interviewAreas(): array
    {
        $catalog = (array) config('organization.department_catalog', []);
        $areas = [];
        foreach ((array) config('organization.interview_areas', []) as $domain) {
            $label = (string) ($catalog[$domain]['interview_label'] ?? $catalog[$domain]['title'] ?? $domain);
            if ($label !== '') {
                $areas[$domain] = $label;
            }
        }

        return $areas;
    }

    // ── Фаза 2: задълбочаване ─────────────────────────────────────────────

    /** Маркираните в обзора домейни (само познати; „Друго"/свободен текст се пропуска тук). */
    private function selectedDomains(array $answers): array
    {
        $known = array_keys($this->interviewAreas());
        $marked = array_map('strval', (array) ($answers['areas'] ?? []));

        return array_values(array_intersect($marked, $known));
    }

    /** Първият избран домейн без задълбочаващ отговор (`area_<domain>`), в конфигурирания ред. */
    private function nextUncoveredDomain(array $selected, array $answers): ?string
    {
        $order = array_keys($this->interviewAreas());
        usort($selected, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        foreach ($selected as $domain) {
            if (! array_key_exists('area_'.$domain, $answers)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * LLM въпрос за ЕДНА област (multi-select), без повторения. Връща [question|null, reply, cost].
     * Ключът се форсира на `area_<domain>` (стабилен, без колизии/презапис).
     *
     * @return array{0: ?array, 1: string, 2: float}
     */
    private function depthQuestion(BusinessProfile $profile, string $domain, array $answers): array
    {
        $catalog = (array) config('organization.department_catalog', []);
        $label = (string) ($catalog[$domain]['interview_label'] ?? $domain);
        $hint = (string) ($catalog[$domain]['mandate'] ?? '');
        $company = $profile->company;
        $asked = $this->askedQuestionTexts($profile);

        $system = <<<'TXT'
Ти си Управителят — задаваш ЕДИН уточняващ въпрос за КОНКРЕТНА област от бизнеса, за да
разбереш нуждите там и да подбереш правилните хора в екипа. Говори на български, топло и по същество.

ПРАВИЛА:
- ЕДИН въпрос с 3–5 готови опции. input_type="checkbox" (множествен избор) и allow_other=true.
- Формулирай в МНОЖЕСТВЕНО число („Кои от тези…?"). БЕЗ превъзходна степен, БЕЗ „кое е НАЙ-важно" —
  целта е ПЪЛНОТА на нуждите, не приоритизиране.
- Питай САМО за областта по-долу. НЕ повтаряй вече зададени въпроси (списъкът е по-долу).
- Върни САМО валиден JSON по схемата: phase="interview", попълни обекта `question`.
TXT;
        $system .= "\n".PromptData::NO_TECH_TERMS;

        $user = "Бизнес: {$company?->name} ({$company?->industry}).\n"
            .'Ситуационен анализ:'."\n".((string) $profile->situational_analysis ?: '(няма)')
            ."\n\n".'ОБЛАСТ ВЪВ ФОКУС: '.$label.($hint !== '' ? ' — '.$hint : '')
            ."\n\n".'Вече зададени въпроси (НЕ повтаряй):'."\n".($asked !== '' ? $asked : '(няма)');

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_interview', $this->schema(), [
                'temperature' => 0.4,
                'num_predict' => 1200,
            ]);
        } catch (\Throwable $e) {
            return [null, '', (float) (LlmUsage::take()['cost_usd'] ?? 0.0)];
        }
        $cost = (float) (LlmUsage::take()['cost_usd'] ?? 0.0);

        $question = $this->normalizeQuestion($raw['question'] ?? null);
        if ($question === null) {
            return [null, '', $cost];
        }
        $question['key'] = 'area_'.$domain;   // стабилен ключ за тази област

        $reply = trim((string) ($raw['reply'] ?? ''));
        if ($reply !== '' && ($reply[0] === '{' || $reply[0] === '[')) {
            $reply = '';   // локален модел понякога натъпква JSON в reply — не го показвай
        }

        return [$question, $reply, $cost];
    }

    /** Текстовете на вече зададените въпроси (от транскрипта) — за да не се повтарят. */
    private function askedQuestionTexts(BusinessProfile $profile): string
    {
        $texts = [];
        foreach ((array) $profile->interview_transcript as $entry) {
            $q = $entry['question'] ?? null;
            if (is_array($q) && ! empty($q['text'])) {
                $texts[] = '- '.(string) $q['text'];
            }
        }

        return implode("\n", $texts);
    }

    /** Кратко обобщение от избраните области (за финалния екран). */
    private function recap(array $selected): array
    {
        $areas = $this->interviewAreas();

        return array_values(array_filter(array_map(fn ($d) => $areas[$d] ?? null, $selected)));
    }

    // ── Схема + нормализация ─────────────────────────────────────────────

    /**
     * JSON схема за хода. Строга (additionalProperties:false + всички ключове в required),
     * за да мине през OpenAI strict Structured Outputs — конвенцията в кода (виж
     * FlowPlannerService). `question` е nullable (обект при въпрос, null при phase="ready").
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'phase' => ['type' => 'string', 'enum' => ['interview', 'ready']],
                'reply' => ['type' => 'string'],
                'question' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'input_type' => ['type' => 'string', 'enum' => ['radio', 'checkbox']],
                        'options' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'value' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                ],
                                'required' => ['value', 'label'],
                            ],
                        ],
                        'allow_other' => ['type' => 'boolean'],
                    ],
                    'required' => ['key', 'text', 'input_type', 'options', 'allow_other'],
                ],
                'recap' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['phase', 'reply', 'question', 'recap'],
        ];
    }

    private function normalizeQuestion($q): ?array
    {
        if (! is_array($q) || empty($q['text'])) {
            return null;
        }

        // Локалните модели често дават само text/label, без машинните key/value.
        // Кодът ГАРАНТИРА: извеждаме липсващите идентификатори, вместо да хвърляме валиден въпрос.
        $options = [];
        foreach ((array) ($q['options'] ?? []) as $i => $opt) {
            $label = is_array($opt) ? trim((string) ($opt['label'] ?? $opt['value'] ?? '')) : trim((string) $opt);
            if ($label === '') {
                continue;
            }
            $value = is_array($opt) ? trim((string) ($opt['value'] ?? '')) : '';
            $options[] = ['value' => $value !== '' ? $value : 'opt_'.($i + 1), 'label' => $label];
        }
        if ($options === []) {
            return null;
        }

        $key = trim((string) ($q['key'] ?? ''));
        if ($key === '') {
            $key = 'q_'.substr(md5((string) $q['text']), 0, 8);   // стабилен ключ от текста на въпроса
        }

        return [
            'key' => $key,
            'text' => (string) $q['text'],
            // Множественият избор е ГАРАНТИРАН в кода — radio само при същинско Да/Не (§multi-select).
            'input_type' => $this->isBinaryQuestion($options) ? 'radio' : 'checkbox',
            'options' => $options,
            'allow_other' => (bool) ($q['allow_other'] ?? true),
            'other_label' => 'Друго (опиши)',
        ];
    }

    /** Същински двоен избор (Да/Не) — единственото изключение от checkbox-а. */
    private function isBinaryQuestion(array $options): bool
    {
        if (count($options) !== 2) {
            return false;
        }
        $labels = mb_strtolower(implode(' ', array_column($options, 'label')));

        return (bool) preg_match('/(^|\W)(да|не|yes|no)(\W|$)/u', $labels);
    }
}
