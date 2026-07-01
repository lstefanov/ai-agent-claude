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
            $question = $this->sweepQuestion($profile);

            return [
                'phase' => 'interview',
                'reply' => $question['default_values'] !== []
                    ? 'Проучването вече подсказа няколко вероятни области. Потвърди, махни или добави това, което е вярно за бизнеса.'
                    : 'За да сглобя точния екип, маркирай ВСИЧКИ области, в които бизнесът има предизвикателства или нужди — спокойно повече от една.',
                'question' => $question,
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

        // Всичко покрито (или достигнат лимит) → готови / follow-up.
        if (preg_match('/^Отговор на «followup_/u', trim($userInput))) {
            $onStage && $onStage('Отговарям…');
            $display = preg_replace('/^Отговор на «followup_[^»]+»:\s*/u', '', trim($userInput));

            return $this->followUpAck($profile, $display, $selected);
        }

        if ($this->isFreeTextFollowUp($userInput)) {
            $onStage && $onStage('Отговарям…');

            return $this->followUpReply($profile, $userInput, $selected);
        }

        return [
            'phase' => 'ready',
            'reply' => 'Имам ясна и пълна представа за нуждите ви. Да сглобим екипа.',
            'question' => null,
            'recap' => $this->recap($selected),
            'cost_usd' => 0.0,
        ];
    }

    /** Свободен текст след структурираните въпроси (не празен старт, не checkbox отговор). */
    private function isFreeTextFollowUp(string $userInput): bool
    {
        $text = trim($userInput);

        return $text !== '' && ! str_starts_with($text, 'Отговор на «');
    }

    /**
     * Follow-up след завършено интервю: памет на целия разговор + проактивен режим
     * (може да върне нов структуриран въпрос при нова нужда).
     *
     * @param  array<int, string>  $selected
     * @return array{phase: string, reply: string, question: ?array, recap: array, cost_usd: float}
     */
    private function followUpReply(BusinessProfile $profile, string $userInput, array $selected): array
    {
        $company = $profile->company;
        $answers = (array) $profile->interview_answers;
        $followups = count(array_filter(
            array_keys($answers),
            fn ($k) => str_starts_with((string) $k, 'followup_'),
        ));
        $maxFollowups = (int) config('organization.manager.max_followup_questions', 3);
        $canAskMore = $followups < $maxFollowups;

        $coveredLabels = array_values(array_filter(array_map(
            fn ($d) => $this->interviewAreas()[$d] ?? null,
            $selected,
        )));

        $system = <<<'TXT'
Ти си Управителят — вече си провел интервюто и имаш ясна представа за бизнеса.
Прочети целия разговор по-долу и отговори на последното съобщение на собственика (на български).
TXT;
        if ($canAskMore) {
            $system .= "\n".<<<'TXT'
АКТИВНО: ако съобщението разкрива НОВА нужда или област (не сред вече покритите), върни phase="interview" + един структуриран въпрос с 3–5 опции (input_type="checkbox", allow_other=true).
Иначе phase="ready" с кратък разговорен отговор в 1–3 изречения.
Не повтаряй вече зададени въпроси от разговора.
При въпрос без конкретен evidence контекст сложи reason=null, source_ids=[], confidence=null, default_values=[].
TXT;
        } else {
            $system .= "\n".<<<'TXT'
Вече си задал достатъчно уточнения — върни phase="ready" с кратък разговорен отговор в 1–3 изречения.
Ако питат за следващи стъпки, насочи ги към анализа и сглобяването на екипа.
TXT;
        }
        $system .= "\n".PromptData::NO_TECH_TERMS;

        $user = "Бизнес: {$company?->name} ({$company?->industry}).\n"
            .'Ситуационен анализ:'."\n".((string) $profile->situational_analysis ?: '(няма)')
            ."\n\n".'Покрити области: '.($coveredLabels !== [] ? implode(', ', $coveredLabels) : '(няма)')
            ."\n\n".'Разговор досега:'."\n".($profile->chatHistory() ?: '(празно)')
            ."\n\n".'Собственик: '.$userInput;

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_interview', $this->schema(), [
                'temperature' => 0.5,
                'num_predict' => 800,
            ]);
        } catch (\Throwable) {
            return $this->readyFallback($selected, 'Благодаря — запазих това. Можеш да продължиш към анализа, когато си готов.', (float) (LlmUsage::take()['cost_usd'] ?? 0.0));
        }

        $cost = (float) (LlmUsage::take()['cost_usd'] ?? 0.0);
        $phase = ($raw['phase'] ?? 'ready') === 'interview' && $canAskMore ? 'interview' : 'ready';
        $question = $phase === 'interview' ? $this->normalizeQuestion($raw['question'] ?? null) : null;
        if ($question !== null) {
            $question['key'] = 'followup_'.substr(md5($question['text']), 0, 8);
        } else {
            $phase = 'ready';
        }

        $reply = trim((string) ($raw['reply'] ?? ''));
        if ($reply !== '' && (($reply[0] ?? '') === '{' || ($reply[0] ?? '') === '[')) {
            $reply = '';
        }
        if ($reply === '' && $question === null) {
            return $this->readyFallback($selected, 'Благодаря — запазих това. Можеш да продължиш към анализа, когато си готов.', $cost);
        }

        return [
            'phase' => $phase,
            'reply' => $reply !== '' ? $reply : (string) ($question['text'] ?? ''),
            'question' => $question,
            'recap' => $this->recap($selected),
            'cost_usd' => $cost,
        ];
    }

    /**
     * Кратко потвърждение след отговор на проактивен follow-up въпрос → винаги ready.
     *
     * @param  array<int, string>  $selected
     * @return array{phase: string, reply: string, question: ?array, recap: array, cost_usd: float}
     */
    private function followUpAck(BusinessProfile $profile, string $answerDisplay, array $selected): array
    {
        $company = $profile->company;
        $system = <<<'TXT'
Ти си Управителят. Собственикът отговори на твой уточняващ въпрос.
Потвърди накратко (1–2 изречения на български), че си разбрал отговора, и че си готов за следващата стъпка.
Върни САМО JSON: phase="ready", reply=текст, question=null, recap=[].
TXT;
        $system .= "\n".PromptData::NO_TECH_TERMS;

        $user = "Бизнес: {$company?->name} ({$company?->industry}).\n"
            .'Разговор досега:'."\n".($profile->chatHistory() ?: '(празно)')
            ."\n\n".'Отговор на уточняващия въпрос: '.$answerDisplay;

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_interview', $this->schema(), [
                'temperature' => 0.4,
                'num_predict' => 300,
            ]);
            $cost = (float) (LlmUsage::take()['cost_usd'] ?? 0.0);
            $reply = trim((string) ($raw['reply'] ?? ''));
            if ($reply !== '' && (($reply[0] ?? '') !== '{' && ($reply[0] ?? '') !== '[')) {
                return $this->readyFallback($selected, $reply, $cost);
            }
        } catch (\Throwable) {
            // fall through
        }

        return $this->readyFallback($selected, 'Записах. Имам ясна представа — можем да продължим към анализа.', (float) (LlmUsage::take()['cost_usd'] ?? 0.0));
    }

    /**
     * @param  array<int, string>  $selected
     * @return array{phase: string, reply: string, question: ?array, recap: array, cost_usd: float}
     */
    private function readyFallback(array $selected, string $reply, float $cost): array
    {
        return [
            'phase' => 'ready',
            'reply' => $reply,
            'question' => null,
            'recap' => $this->recap($selected),
            'cost_usd' => $cost,
        ];
    }

    // ── Фаза 1: обзор ─────────────────────────────────────────────────────

    /** Детерминистичен multi-select върху всички бизнес области (стабилен ключ `areas`). */
    private function sweepQuestion(BusinessProfile $profile): array
    {
        $options = [];
        foreach ($this->interviewAreas() as $domain => $label) {
            $options[] = ['value' => $domain, 'label' => $label];
        }

        $suggested = $this->researchSuggestedAreas($profile);
        $defaultValues = array_values(array_unique(array_map(
            fn ($area) => (string) $area['domain'],
            array_filter($suggested, fn ($area) => (bool) ($area['default'] ?? true)),
        )));
        $sourceIds = array_values(array_unique(array_merge(...array_map(
            fn ($area) => (array) ($area['source_ids'] ?? []),
            $suggested ?: [[]],
        ))));
        $confidence = $suggested === []
            ? null
            : round(max(array_map(fn ($area) => (float) ($area['confidence'] ?? 0.5), $suggested)), 2);

        return [
            'key' => 'areas',
            'text' => $defaultValues !== []
                ? 'Това са областите, които проучването подсказа. Кои от тях да останат във фокус и какво още липсва?'
                : 'В кои от тези области виждаш предизвикателства или нужди за бизнеса си?',
            'input_type' => 'checkbox',
            'options' => $options,
            'allow_other' => true,
            'other_label' => 'Друго (опиши)',
            'reason' => $defaultValues !== []
                ? 'Маркирах предварително областите, за които има публични сигнали или силни хипотези.'
                : 'Публичните данни не стигат за уверени предположения, затова започвам с широк обзор.',
            'source_ids' => array_slice($sourceIds, 0, 8),
            'confidence' => $confidence,
            'default_values' => $defaultValues,
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

    /** @return array<int, array<string, mixed>> */
    private function researchSuggestedAreas(BusinessProfile $profile): array
    {
        $known = $this->interviewAreas();
        $out = [];

        foreach ((array) data_get((array) $profile->research, 'suggested_areas', []) as $area) {
            if (! is_array($area)) {
                continue;
            }
            $domain = (string) ($area['domain'] ?? '');
            if (! isset($known[$domain])) {
                continue;
            }
            $out[] = [
                'domain' => $domain,
                'label' => $known[$domain],
                'reason' => trim((string) ($area['reason'] ?? '')),
                'source_ids' => array_values(array_filter(array_map('strval', (array) ($area['source_ids'] ?? [])))),
                'confidence' => is_numeric($area['confidence'] ?? null) ? round(max(0, min(1, (float) $area['confidence'])), 2) : 0.55,
                'default' => (bool) ($area['default'] ?? true),
            ];
        }

        return $out;
    }

    private function researchContext(BusinessProfile $profile, ?string $domain = null): string
    {
        $research = (array) $profile->research;
        $lines = [];
        $sourceIds = [];

        foreach ($this->researchSuggestedAreas($profile) as $area) {
            if ($domain !== null && $area['domain'] !== $domain) {
                continue;
            }
            $lines[] = '- Хипотеза: '.$area['label'].($area['reason'] !== '' ? ' — '.$area['reason'] : '');
            $sourceIds = array_merge($sourceIds, (array) $area['source_ids']);
        }

        $gaps = [];
        foreach ((array) ($research['gaps'] ?? []) as $gap) {
            if (! is_array($gap)) {
                continue;
            }
            $gapDomain = trim((string) ($gap['domain'] ?? ''));
            if ($domain !== null && $gapDomain !== '' && $gapDomain !== $domain) {
                continue;
            }
            $gaps[] = $gap;
            $sourceIds = array_merge($sourceIds, (array) ($gap['source_ids'] ?? []));
        }

        foreach (array_slice($gaps, 0, 4) as $gap) {
            $lines[] = '- Gap: '.(string) ($gap['question'] ?? $gap['reason'] ?? '');
        }

        foreach (array_slice((array) data_get($research, 'report.likely_needs', []), 0, 4) as $need) {
            $need = trim((string) $need);
            if ($need !== '') {
                $lines[] = '- Вероятна нужда: '.$need;
            }
        }
        foreach (array_slice((array) data_get($research, 'report.automation_opportunities', []), 0, 4) as $opportunity) {
            $opportunity = trim((string) $opportunity);
            if ($opportunity !== '') {
                $lines[] = '- Възможна автоматизация: '.$opportunity;
            }
        }
        foreach (array_slice((array) data_get($research, 'customer_voice.complaints', []), 0, 3) as $complaint) {
            $complaint = trim((string) $complaint);
            if ($complaint !== '') {
                $lines[] = '- Клиентски сигнал: '.$complaint;
            }
        }

        $evidence = $this->evidenceById($profile);
        foreach (array_slice(array_values(array_unique($sourceIds)), 0, 5) as $sourceId) {
            $item = $evidence[$sourceId] ?? null;
            if (! $item) {
                continue;
            }
            $snippet = trim((string) ($item['snippet'] ?? ''));
            $title = trim((string) ($item['title'] ?? $sourceId));
            $lines[] = '- Evidence '.$sourceId.': '.$title.($snippet !== '' ? ' — '.mb_substr($snippet, 0, 240) : '');
        }

        if ($lines === []) {
            return 'Няма надеждни специфични публични сигнали за тази област. Питай за вътрешни цели, капацитет, bottlenecks, честота и желани автоматизации.';
        }

        return implode("\n", array_slice($lines, 0, 16));
    }

    /** @return array<string, array<string, mixed>> */
    private function evidenceById(BusinessProfile $profile): array
    {
        $out = [];
        foreach ((array) data_get((array) $profile->research, 'evidence', []) as $item) {
            if (is_array($item) && ! empty($item['id'])) {
                $out[(string) $item['id']] = $item;
            }
        }

        return $out;
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
        $asked = $this->askedContext($profile);

        $system = <<<'TXT'
Ти си Управителят — задаваш ЕДИН уточняващ въпрос за КОНКРЕТНА област от бизнеса, за да
разбереш нуждите там и да подбереш правилните хора в екипа. Говори на български, топло и по същество.

ПРАВИЛА:
- ЕДИН въпрос с 3–5 готови опции. input_type="checkbox" (множествен избор) и allow_other=true.
- Формулирай в МНОЖЕСТВЕНО число („Кои от тези…?"). БЕЗ превъзходна степен, БЕЗ „кое е НАЙ-важно" —
  целта е ПЪЛНОТА на нуждите, не приоритизиране.
- Питай САМО за областта по-долу. НЕ повтаряй вече зададени въпроси (списъкът е по-долу).
- Ако проучването има хипотеза, формулирай като потвърждение/корекция: „Видях X — кои от тези са верни?".
- Дай приоритет на вътрешни gaps: цели, капацитет, bottlenecks, бюджет/честота, желани автоматизации.
- В question.reason обясни защо питаш; source_ids/default_values може да са [].
- Върни САМО валиден JSON по схемата: phase="interview", попълни обекта `question`.
TXT;
        $system .= "\n".PromptData::NO_TECH_TERMS;

        $user = "Бизнес: {$company?->name} ({$company?->industry}).\n"
            .'Ситуационен анализ:'."\n".((string) $profile->situational_analysis ?: '(няма)')
            ."\n\n".'ОБЛАСТ ВЪВ ФОКУС: '.$label.($hint !== '' ? ' — '.$hint : '')
            ."\n\n".'Данни от проучването за тази област:'."\n".$this->researchContext($profile, $domain)
            ."\n\n".'Вече зададени въпроси и отговори (НЕ повтаряй):'."\n".($asked !== '' ? $asked : '(няма)');

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

    /** Въпроси + отговори от транскрипта — за depth/follow-up без повторения. */
    private function askedContext(BusinessProfile $profile): string
    {
        $rows = (array) $profile->interview_transcript;
        $lines = [];
        for ($i = 0, $n = count($rows); $i < $n; $i++) {
            $e = $rows[$i];
            if (! empty($e['question']['text'])) {
                $lines[] = 'Въпрос: '.(string) $e['question']['text'];
                $next = $rows[$i + 1] ?? null;
                if (($next['role'] ?? '') === 'user' && ! empty($next['content'])) {
                    $lines[] = 'Отговор: '.(string) $next['content'];
                }
            }
        }

        return implode("\n", $lines);
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
                        'reason' => ['type' => ['string', 'null']],
                        'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence' => ['type' => ['number', 'null']],
                        'default_values' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['key', 'text', 'input_type', 'options', 'allow_other', 'reason', 'source_ids', 'confidence', 'default_values'],
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
            'reason' => filled($q['reason'] ?? null) ? (string) $q['reason'] : null,
            'source_ids' => array_values(array_filter(array_map('strval', (array) ($q['source_ids'] ?? [])))),
            'confidence' => is_numeric($q['confidence'] ?? null) ? round(max(0, min(1, (float) $q['confidence'])), 2) : null,
            'default_values' => $this->validDefaultValues((array) ($q['default_values'] ?? []), $options),
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

    /** @return array<int, string> */
    private function validDefaultValues(array $values, array $options): array
    {
        $valid = array_fill_keys(array_map(fn ($opt) => (string) $opt['value'], $options), true);
        $out = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if (isset($valid[$value])) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }
}
