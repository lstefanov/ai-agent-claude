<?php

namespace App\Services;

use App\Agents\Tools\BraveSearchTool;
use App\Agents\Tools\KnowledgeSearchTool;
use App\Models\Company;
use App\Models\FlowDraft;
use App\Models\FlowDraftMessage;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Разговорният създател на Flow в клиентския портал. По модел на
 * BuilderAssistantService, но изходът е СТРУКТУРИРАН JSON (интервю въпрос +
 * сглобяващо се описание), не graph ops. Планерът предлага, кодът гарантира:
 * всеки ход се валидира спрямо схемата (parseJson + validate).
 *
 * Инструменти (read-only) през AgentLoop: knowledge_search (фирмената база
 * знания) → първо; web_search (Brave) → когато знанията не достигат.
 */
class ClientFlowWizardService
{
    public function __construct(
        private GeneratorService $generator,
        private AgentLoop $loop,
        private KnowledgeService $knowledge,
        private WizardScriptService $scripts,
    ) {}

    /**
     * Провайдър+модел: client_wizard → fallback към Builder Copilot → planner
     * (ако е cloud) → openai. Само cloud провайдъри с tool calling.
     *
     * @return array{provider: string, model: string}
     */
    public function providerModel(): array
    {
        $provider = (string) config('services.client_wizard.provider', '');
        $model = (string) config('services.client_wizard.model', '');

        // Празно → ползвай вече конфигурирания Builder Copilot модел (cheap cloud).
        if ($provider === '') {
            $provider = (string) config('services.builder_assistant.provider', '');
            $model = $model !== '' ? $model : (string) config('services.builder_assistant.model', '');
        }

        // Окончателен fallback: planner провайдъра ако е cloud, иначе openai.
        if ($provider === '' || $provider === 'ollama' || ! in_array($provider, GeneratorService::PROVIDERS, true)) {
            $gen = (string) config('services.generator.provider', 'openai');
            $provider = ($gen !== 'ollama' && in_array($gen, GeneratorService::PROVIDERS, true)) ? $gen : 'openai';
            $model = (string) config('services.client_wizard.model', ''); // не бъркай моделите между провайдъри
        }

        if ($model === '') {
            $model = (string) config("services.{$provider}.model", '');
        }

        return ['provider' => $provider, 'model' => $model];
    }

    public function isAvailable(): bool
    {
        return ! empty(config('services.'.$this->providerModel()['provider'].'.api_key'));
    }

    /**
     * Един ход: детерминистичен скрипт за топ-сферите (C2), иначе пълният LLM
     * режим. Връща един и същ контракт, така че job-ът/фронтендът не се променят.
     *
     * @return array{phase: string, reply: string, question: ?array, description_draft: ?string, recap: ?array, suggested_title: ?string, cost_usd: ?float}
     */
    public function turn(FlowDraft $draft, FlowDraftMessage $userMessage, ?callable $onStage = null): array
    {
        $mode = data_get($draft->script, 'mode');

        if ($mode === 'llm') {
            return $this->llmTurn($draft, $userMessage, $onStage);
        }

        if ($mode === 'script') {
            // Свободен текст по средата на скрипт (без structured answer) → клиентът
            // иска да отклони → премини на гъвкавия LLM режим.
            if (blank($userMessage->payload)) {
                $draft->update(['script' => ['mode' => 'llm']]);

                return $this->llmTurn($draft, $userMessage, $onStage);
            }

            return $this->scriptTurn($draft, fn () => $this->scripts->nextStep($draft));
        }

        // Първи ход без скрипт → опитай да разпознаеш сфера.
        if (($domain = $this->scripts->detect((string) $userMessage->content)) !== null) {
            return $this->scriptTurn($draft, fn () => $this->scripts->start($draft, $domain));
        }

        $draft->update(['script' => ['mode' => 'llm']]);

        return $this->llmTurn($draft, $userMessage, $onStage);
    }

    /**
     * C5: върни скрипта на даден въпрос (редакция на минал отговор). null, ако
     * ключът не съществува в текущия скрипт.
     */
    public function reviseTo(FlowDraft $draft, string $key): ?array
    {
        $domain = data_get($draft->script, 'domain');
        $keys = collect((array) config("wizard_scripts.domains.{$domain}.questions", []))->pluck('key');
        if (! $keys->contains($key)) {
            return null;
        }

        return $this->scriptTurn($draft, fn () => $this->scripts->goToStep($draft, $key));
    }

    /**
     * Обвива детерминистичен script ход: атрибутира евентуалния topic LLM call
     * към 'client_wizard'/сесията (костовете) и прибира цената му в резултата
     * (за да влезе в flow_draft_messages.cost_usd).
     */
    private function scriptTurn(FlowDraft $draft, callable $fn): array
    {
        LlmUsage::take(); // reset акумулатора за този ход
        LlmContext::set([
            'purpose' => 'client_wizard',
            'session_id' => $draft->session,
            'company_id' => $draft->company_id,
        ]);

        try {
            $result = $fn();
        } finally {
            LlmContext::clear();
        }

        $result['cost_usd'] = LlmUsage::take()['cost_usd'];

        return $result;
    }

    /**
     * Пълният LLM режим: агентен tool-calling цикъл → валидиран JSON. За непознати
     * сфери и за „свободен текст" отклонения от скрипта.
     *
     * @return array{phase: string, reply: string, question: ?array, description_draft: ?string, recap: ?array, suggested_title: ?string, cost_usd: ?float}
     */
    private function llmTurn(FlowDraft $draft, FlowDraftMessage $userMessage, ?callable $onStage = null): array
    {
        $draft->loadMissing('company');

        ['provider' => $provider, 'model' => $model] = $this->providerModel();
        $maxSteps = max(1, (int) config('services.client_wizard.max_steps', 6));
        $maxQuestions = max(1, (int) config('services.client_wizard.max_questions', 8));
        $questionsAsked = $this->questionsAsked($draft, $userMessage);
        $forceReady = $questionsAsked >= $maxQuestions;

        $company = $draft->company;
        $knowledgeTool = new KnowledgeSearchTool(app(KnowledgeService::class), $draft->company_id);
        $webTool = app(BraveSearchTool::class);

        $tools = [
            ['name' => $knowledgeTool->name(), 'description' => $knowledgeTool->description(), 'parameters' => $knowledgeTool->parameters()],
            ['name' => $webTool->name(), 'description' => $webTool->description(), 'parameters' => $webTool->parameters()],
        ];

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($draft, $questionsAsked, $maxQuestions, $forceReady)],
            ...$this->history($draft, $userMessage),
            ['role' => 'user', 'content' => (string) $userMessage->content],
        ];

        $options = ['temperature' => 0.3, 'num_predict' => 2500, 'http_timeout' => 120];

        LlmUsage::take();
        LlmContext::set([
            'purpose' => 'client_wizard',
            'session_id' => $draft->session,
            'company_id' => $draft->company_id,
        ]);

        try {
            $result = $this->loop->run(
                provider: $provider,
                model: $model,
                messages: $messages,
                tools: $tools,
                executor: fn (string $name, array $args): string => match ($name) {
                    'knowledge_search' => $knowledgeTool->execute($args),
                    'web_search' => $webTool->execute($args),
                    default => json_encode(['error' => "Непознат инструмент: {$name}"], JSON_UNESCAPED_UNICODE),
                },
                maxSteps: $maxSteps,
                options: $options,
                onToolCall: $onStage
                    ? fn (string $name, array $args) => $onStage($this->stageLabel($name))
                    : null,
                wrapUpPrompt: $this->jsonInstruction($forceReady),
                wrapUpOptions: $options,
            );

            $content = $result['content'];
            $data = $this->parseJson($content);
            $valid = $data ? $this->validate($data) : null;

            // Невалиден JSON → един retry с nudge (без инструменти).
            if ($valid === null) {
                $retryMessages = [
                    ...$messages,
                    ['role' => 'assistant', 'content' => $content],
                    ['role' => 'user', 'content' => 'Отговорът не беше валиден JSON. Върни САМО валиден JSON обект по схемата, без никакъв друг текст или ```.'],
                ];
                $retry = $this->generator->chatTurn($provider, $model, $retryMessages, [], $options);
                $valid = ($d = $this->parseJson($retry['content'])) ? $this->validate($d) : null;
                if ($valid === null) {
                    $content = $retry['content'] !== '' ? $retry['content'] : $content;
                }
            }
        } catch (Throwable $e) {
            Log::error('[ClientWizard] turn failed: '.$e->getMessage());
            LlmContext::clear();

            return $this->softFallback($draft, 'Възникна проблем. Опиши ми с думи какво искаш и ще продължим.');
        }

        LlmContext::clear();
        $usage = LlmUsage::take();

        if ($valid === null) {
            // Меко: ползвай текста като отговор, остави свободното поле да движи разговора.
            return $this->softFallback($draft, $content !== '' ? $content : 'Разкажи ми малко повече за това, което искаш.', $usage['cost_usd']);
        }

        // Принудителна чернова при изчерпани въпроси.
        if ($forceReady && $valid['phase'] === 'interview') {
            $valid['phase'] = 'ready';
            $valid['question'] = null;
            $valid['reply'] = $valid['reply'] !== '' ? $valid['reply'] : 'Мисля, че имаме достатъчно. Прегледай описанието вдясно — можеш да го редактираш или да генерираш.';
            $valid['description_draft'] = $valid['description_draft'] ?: $draft->description;
        }

        return [...$valid, 'cost_usd' => $usage['cost_usd']];
    }

    // ──────────────────────────────────────────────────────────────────────

    private function questionsAsked(FlowDraft $draft, FlowDraftMessage $userMessage): int
    {
        return $draft->messages()
            ->where('role', 'assistant')
            ->where('id', '<', $userMessage->id)
            ->whereNotNull('payload')
            ->get(['payload'])
            ->filter(fn ($m) => is_array($m->payload) && ! empty($m->payload['question']))
            ->count();
    }

    /** @return list<array<string, string>> */
    private function history(FlowDraft $draft, FlowDraftMessage $userMessage): array
    {
        $limit = max(0, (int) config('services.client_wizard.history_limit', 20));

        return $draft->messages()
            ->where('id', '<', $userMessage->id)
            ->where('status', '!=', 'failed')
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $this->excerpt((string) $m->content, 2000)])
            ->values()
            ->all();
    }

    private function systemPrompt(FlowDraft $draft, int $questionsAsked, int $maxQuestions, bool $forceReady): string
    {
        $company = $draft->company;
        $answers = collect((array) $draft->answers)
            ->map(fn ($v, $k) => '- '.$k.': '.(is_array($v) ? implode(', ', $v) : $v))
            ->implode("\n") ?: '(още нищо)';
        $draftDesc = trim((string) $draft->description) ?: '(още празно)';

        // C1: прозагрято фирмено знание → ботът предлага теми без knowledge_search round-trip.
        $kb = $company ? $this->knowledgePrewarm($company) : '';
        $kbSection = $kb !== ''
            ? "\n\nЗНАНИЕ ЗА ФИРМАТА (резюме — ползвай го за КОНКРЕТНИ предложения на теми):\n{$kb}"
            : '';

        $stopRule = $forceReady
            ? "\nВАЖНО: Вече зададе достатъчно въпроси ({$questionsAsked}). НЕ задавай повече въпроси — върни phase=ready с финално, самостоятелно описание и suggested_title."
            : "Зададени въпроси досега: {$questionsAsked} (максимум {$maxQuestions}).";

        return <<<PROMPT
Ти си експертен консултант, който помага на БИЗНЕС да опише точно какъв автоматизиран
AI flow иска. Клиентът обикновено НЕ знае как да го опише и няма точна идея — затова го
интервюираш с прости въпроси с готови опции и сглобяваш вместо него пълно, годно за
изпълнение описание.

ЗА ФИРМАТА:
- Име: {$company?->name}
- Сектор: {$company?->industry}
- Описание: {$this->excerpt((string) $company?->description, 600)}{$kbSection}

СЪБРАНО ДОСЕГА:
{$answers}

ТЕКУЩА ЧЕРНОВА НА ОПИСАНИЕТО (показва се на клиента, дописвай я):
{$draftDesc}

КАК РАБОТИШ:
1. Задавай по ЕДИН въпрос на стъпка, с готови опции (radio = един отговор, checkbox = няколко).
2. ВИНАГИ добавяй опция „Друго", когато опциите може да не са изчерпателни (allow_other=true).
3. НИКОГА не питай за самото тяло/основен текст (то се подразбира) — питай за СТРУКТУРАТА и
   добавките (картинка? hook? хаштагове? линк? CTA? формат? дължина?).
4. За темите ползвай ПЪРВО „ЗНАНИЕ ЗА ФИРМАТА" по-горе и предложи 3–5 КОНКРЕТНИ теми, свързани
   с бизнеса и трендовете. Извиквай knowledge_search САМО за конкретен детайл, който липсва в
   резюмето; web_search САМО ако знание изобщо няма. Не хаби ходове за излишни търсения.
5. Стъпвай върху „СЪБРАНО ДОСЕГА" — не повтаряй въпроси.
6. На всеки ход дописвай description_draft (на български): какво да прави flow-ът, за коя
   платформа/технология, тема, компоненти/структура, тон, аудитория, език, ограничения/активи.
   Описанието трябва да е САМОСТОЯТЕЛНО — вкарай конкретиката в текста (без placeholder-и като
   {{topic}}); то ще се подаде на автоматичния планер дословно.
7. Премини на phase=ready, когато са ясни: намерение, платформа/технология, тема, компоненти,
   тон/аудитория и език. Тогава попълни финалното описание и suggested_title.
{$stopRule}

ФОРМАТ НА ОТГОВОРА — върни САМО един валиден JSON обект, без друг текст и без ```:
{
  "phase": "interview | ready",
  "reply": "кратък разговорен текст към клиента (на български)",
  "question": {                         // само при phase=interview
    "key": "platform",                  // машинен ключ за този въпрос
    "text": "За коя социална мрежа е постът?",
    "input_type": "radio | checkbox",
    "options": [ { "value": "facebook", "label": "Facebook", "hint": "по избор" } ],
    "allow_other": true,
    "other_label": "Друго (опиши)"
  },
  "description_draft": "пълно/частично описание на flow-а",
  "recap": ["платформа: Facebook", "тема: …"],
  "suggested_title": "Кратко заглавие на flow-а"
}
PROMPT;
    }

    /**
     * C1: компактно, кешнато резюме на фирменото знание за системния промпт — за
     * да предлага ботът конкретни теми още на първия ход, без отделен
     * knowledge_search round-trip. Празно, ако базата знания е изключена/празна.
     */
    private function knowledgePrewarm(Company $company): string
    {
        if (! KnowledgeService::enabled($company) || $this->knowledge->isEmpty($company)) {
            return '';
        }

        return Cache::remember("client_wizard_kb_{$company->id}", now()->addMinutes(10), function () use ($company) {
            $summary = $this->knowledge->summary($company);
            $lines = [];

            if (! empty($summary['titles'])) {
                $lines[] = 'Материали: '.implode(', ', array_slice($summary['titles'], 0, 8)).'.';
            }
            if (! empty($summary['folders'])) {
                $lines[] = 'Папки: '.implode(', ', $summary['folders']).'.';
            }

            // Структурирани факти (услуги/цени/локации) — същината за предложения на теми.
            $profile = $this->excerpt($this->knowledge->ownProfileBlock($company), 1400);
            if ($profile !== '') {
                $lines[] = $profile;
            }

            return implode("\n", $lines);
        });
    }

    private function jsonInstruction(bool $forceReady): string
    {
        return $forceReady
            ? '(Системно: приключи. Върни САМО валиден JSON с phase=ready, финално самостоятелно description_draft и suggested_title — без инструменти, без друг текст.)'
            : '(Системно: върни САМО валиден JSON по схемата — без инструменти, без друг текст.)';
    }

    private function stageLabel(string $tool): string
    {
        return match ($tool) {
            'knowledge_search' => 'Проверявам какво знам за фирмата…',
            'web_search' => 'Търся в интернет…',
            default => 'Мисля…',
        };
    }

    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Свали ```json … ``` ограждания, ако моделът ги е сложил.
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $content);
            $content = trim((string) $content);
        }

        // Вземи първия {...} блок, ако има предговор/послеслов.
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($content, $start, $end - $start + 1);

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Валидира/нормализира LLM JSON-а. Връща чист масив или null.
     *
     * @return array{phase: string, reply: string, question: ?array, description_draft: ?string, recap: ?array, suggested_title: ?string}|null
     */
    private function validate(array $d): ?array
    {
        $phase = in_array($d['phase'] ?? null, ['interview', 'ready'], true) ? $d['phase'] : null;
        if ($phase === null) {
            return null;
        }

        $question = null;
        if ($phase === 'interview') {
            $q = $d['question'] ?? null;
            if (! is_array($q)) {
                return null;
            }

            $inputType = in_array($q['input_type'] ?? null, ['radio', 'checkbox'], true) ? $q['input_type'] : null;
            $text = trim((string) ($q['text'] ?? ''));

            $options = [];
            foreach ((array) ($q['options'] ?? []) as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $value = trim((string) ($opt['value'] ?? $opt['label'] ?? ''));
                $label = trim((string) ($opt['label'] ?? $opt['value'] ?? ''));
                if ($value === '' || $label === '') {
                    continue;
                }
                $hint = trim((string) ($opt['hint'] ?? ''));
                $options[] = ['value' => $value, 'label' => $label, 'hint' => $hint !== '' ? $hint : null];
            }

            if ($inputType === null || $text === '' || $options === []) {
                return null;
            }

            $question = [
                'key' => trim((string) ($q['key'] ?? '')) ?: 'q',
                'text' => $text,
                'input_type' => $inputType,
                'options' => $options,
                'allow_other' => (bool) ($q['allow_other'] ?? true),
                'other_label' => trim((string) ($q['other_label'] ?? '')) ?: 'Друго (опиши)',
            ];
        }

        $recap = isset($d['recap']) && is_array($d['recap'])
            ? array_values(array_filter(array_map(fn ($r) => trim((string) $r), $d['recap']), fn ($r) => $r !== ''))
            : null;

        return [
            'phase' => $phase,
            'reply' => trim((string) ($d['reply'] ?? '')),
            'question' => $question,
            'description_draft' => isset($d['description_draft']) ? trim((string) $d['description_draft']) : null,
            'recap' => $recap ?: null,
            'suggested_title' => isset($d['suggested_title']) ? (trim((string) $d['suggested_title']) ?: null) : null,
        ];
    }

    /** @return array{phase: string, reply: string, question: ?array, description_draft: ?string, recap: ?array, suggested_title: ?string, cost_usd: ?float} */
    private function softFallback(FlowDraft $draft, string $reply, ?float $cost = null): array
    {
        return [
            'phase' => 'interview',
            'reply' => $reply,
            'question' => null,
            'description_draft' => $draft->description,
            'recap' => null,
            'suggested_title' => null,
            'cost_usd' => $cost,
        ];
    }

    private function excerpt(string $text, int $limit): string
    {
        $text = trim($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'… [съкратено]' : $text;
    }
}
