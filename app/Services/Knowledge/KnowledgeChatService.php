<?php

namespace App\Services\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeChatMessage;
use App\Services\KnowledgeService;
use App\Services\OllamaService;
use App\Services\OpenAiChatService;
use App\Services\PerplexitySearchService;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use Illuminate\Support\Facades\Log;

/**
 * Чатът "Тествай знанията": въпрос на човешки език → hybrid retrieval върху
 * базата знания (чанкове + факти) → LLM съставя отговор на български с
 * цитирани източници. ЛОКАЛЕН модел по подразбиране (безплатни въпроси,
 * services.knowledge.chat_provider=ollama → BgGPT); cloud при нужда.
 *
 * Когато базата няма покритие (нула hits, или ДА/НЕ проверка отсъди, че
 * откъсите не отговарят на въпроса) чатът НЕ се предава: търси в интернет
 * (Perplexity), систематизира намереното и маркира отговора като
 * source_type=web. Слабото покритие пак се логва като "пропуск" — точно
 * както когато агент удари на камък. Решението се взима по ПРЕЦЕНКА на модела,
 * не по retrieval score (подлъгва се при споделени ключови думи).
 */
class KnowledgeChatService
{
    private const CONTEXT_MAX_CHARS = 7000;

    private const HISTORY_MAX_CHARS = 3000;

    public function __construct(
        private KnowledgeService $knowledge,
        private PerplexitySearchService $webSearch,
    ) {}

    /**
     * @return array{content: string, sources: array<int, array<string, mixed>>, source_type: string, cost_usd: float}
     */
    public function turn(Company $company, string $question, string $session, ?callable $onStage = null, ?int $replyId = null): array
    {
        $onStage && $onStage('Търся в базата знания…');

        // Gap логването е нарочно ВКЛЮЧЕНО: въпрос без покритие е същият
        // сигнал "качи документ за това" като агентско търсене на сухо.
        $hits = $this->knowledge->search(
            $company,
            $question,
            topK: 10,
            llmContext: ['company_id' => $company->id],
            nodeKey: 'knowledge-chat',
        );

        // Свързваме заявките на този ход (Perplexity + синтез) със съобщението —
        // session_id за popup-а „Детайли". Сетва се СЛЕД search(), защото неговият
        // embedding презаписва LlmContext.
        if ($replyId !== null) {
            LlmContext::set(['purpose' => 'knowledge_chat', 'company_id' => $company->id, 'session_id' => 'kbchat-'.$replyId]);
        }

        try {
            // Празно извличане → директно интернет.
            if ($hits === []) {
                return $this->answerFromWeb($company, $question, $session, $onStage);
            }

            // Опитай от базата; ако моделът прецени, че откъсите НЕ отговарят на
            // въпроса (NEED_WEB или честно "няма информация") → интернет fallback.
            // НЕ разчитаме на retrieval score — подлъгва се при споделени ключови думи.
            $kb = $this->answerFromKnowledge($company, $question, $session, $hits, $onStage);
            if ($kb === null) {
                return $this->answerFromWeb($company, $question, $session, $onStage);
            }

            return $kb;
        } finally {
            if ($replyId !== null) {
                LlmContext::clear();
            }
        }
    }

    /**
     * Отговор от базата знания (нормалният път).
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @return array{content: string, sources: array<int, array<string, mixed>>, source_type: string, cost_usd: float}|null null = базата не покрива въпроса → интернет
     */
    private function answerFromKnowledge(Company $company, string $question, string $session, array $hits, ?callable $onStage): ?array
    {
        $sources = [];
        $context = '';
        foreach ($hits as $i => $hit) {
            $n = $i + 1;
            $label = $hit['kind'] === 'fact'
                ? 'ФАКТ ('.$hit['category'].($hit['location'] ? ', '.$hit['location'] : '').')'
                : ($hit['url'] ?: ($hit['source_type'] === 'note' ? 'бележка' : 'документ'));

            $entry = "[{$n}] «{$hit['title']}» — {$label}\n".trim($hit['content'])."\n\n";
            if (mb_strlen($context) + mb_strlen($entry) > self::CONTEXT_MAX_CHARS) {
                break;
            }
            $context .= $entry;

            $sources[] = [
                'n' => $n,
                'kind' => $hit['kind'],
                'title' => $hit['title'],
                'url' => $hit['url'],
                'category' => $hit['category'],
                'location' => $hit['location'],
                'resource_id' => $hit['resource_id'],
                'page_id' => $hit['page_id'],
                'fact_id' => $hit['fact_id'],
                'score' => $hit['score'],
            ];
        }

        // Надеждна ДА/НЕ проверка дали базата изобщо покрива въпроса (преди
        // да композираме) — заменя крехкото разчитане на фразировката.
        if (! $this->coversQuestion($company, $question, $context)) {
            LlmUsage::take(); // изхвърли usage-а на проверката — web ще го поеме

            return null;
        }

        $onStage && $onStage('Съставям отговор…');

        $system = <<<PROMPT
Ти си асистентът на базата знания на фирма "{$company->name}". Отговаряш на въпроси САМО въз основа на подадените ОТКЪСИ от базата знания.

Правила:
- Отговаряй на български, ясно и структурирано (markdown: списъци/таблици при цени и условия).
- Цените, числата и условията предавай ТОЧНО както са в откъсите — нищо измислено.
- Когато въпросът не уточнява вариант (напр. пол мъже/жени), покажи ВСИЧКИ налични варианти от откъсите.
- Свързвай информацията от няколко откъса в един цялостен отговор (описание + цени + допълнителна информация).
- Цитирай източниците с номерата им в квадратни скоби, напр. [1], [2].
- Ако подадените откъси НЕ съдържат отговор на конкретния въпрос (дори да се споменават свързани неща — напр. самия продукт, но не и питаното за него), отговори САМО с думата: NEED_WEB — без друг текст и без да измисляш.
PROMPT;

        $user = "--- ОТКЪСИ ОТ БАЗАТА ЗНАНИЯ ---\n{$context}"
            .$this->historyBlock($company, $session)
            ."--- ВЪПРОС ---\n".$question;

        $content = $this->runCompose($company, $system, $user);

        // Защитна мрежа: ако въпреки проверката отговорът честно казва „няма" (или NEED_WEB).
        if ($this->signalsNoCoverage($content)) {
            LlmUsage::take();

            return null;
        }

        $usage = LlmUsage::take();

        return [
            'content' => trim($content) !== '' ? trim($content) : 'Не успях да съставя отговор — опитай отново.',
            'sources' => $sources,
            'source_type' => 'kb',
            'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
        ];
    }

    /**
     * Интернет fallback: Perplexity търсене → синтез на български с цитати.
     *
     * @return array{content: string, sources: array<int, array<string, mixed>>, source_type: string, cost_usd: float}
     */
    private function answerFromWeb(Company $company, string $question, string $session, ?callable $onStage): array
    {
        $onStage && $onStage('Нямам това в базата знания — търся в интернет…');

        $results = [];
        try {
            $results = $this->webSearch->search($question, ['country' => 'BG']);
        } catch (\Throwable $e) {
            // Perplexity недостъпен/без ключ → честен отговор, без да чупим хода.
            Log::warning('[KnowledgeChat] Web fallback failed: '.$e->getMessage());
        }

        if ($results === []) {
            $usage = LlmUsage::take();

            return [
                'content' => 'В базата знания няма информация по този въпрос, а търсенето в интернет не върна резултати. '
                    .'Добавете ресурс (URL, файл или бележка) по темата, за да може чатът да отговаря.',
                'sources' => [],
                'source_type' => 'web',
                'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
            ];
        }

        $sources = [];
        $context = '';
        foreach ($results as $i => $result) {
            $n = $i + 1;
            $title = (string) ($result['title'] ?? 'Резултат');
            $url = (string) ($result['url'] ?? '');
            $snippet = trim((string) ($result['snippet'] ?? $result['description'] ?? ''));
            $date = $result['date'] ?? $result['last_updated'] ?? null;

            $entry = "[{$n}] «{$title}»".($date ? " ({$date})" : '')." — {$url}\n{$snippet}\n\n";
            if (mb_strlen($context) + mb_strlen($entry) > self::CONTEXT_MAX_CHARS) {
                break;
            }
            $context .= $entry;

            $sources[] = [
                'n' => $n,
                'kind' => 'web',
                'title' => $title,
                'url' => $url,
                'category' => null,
                'location' => null,
                'resource_id' => null,
                'page_id' => null,
                'fact_id' => null,
                'score' => null,
            ];
        }

        $onStage && $onStage('Систематизирам резултатите…');

        $system = <<<PROMPT
Ти си асистентът на фирма "{$company->name}". Базата знания НЯМА информация по въпроса, затова отговаряш въз основа на РЕЗУЛТАТИ ОТ ИНТЕРНЕТ ТЪРСЕНЕ.

Правила:
- Отговаряй на български, ясно и структурирано (markdown: списъци/таблици при нужда).
- Систематизирай намереното в кратък, полезен отговор — не преразказвай резултатите един по един.
- Цитирай източниците с номерата им в квадратни скоби, напр. [1], [2].
- Не измисляй нищо извън подадените резултати; ако те не отговарят на въпроса, кажи го честно.
PROMPT;

        $user = "--- РЕЗУЛТАТИ ОТ ИНТЕРНЕТ ТЪРСЕНЕ ---\n{$context}"
            .$this->historyBlock($company, $session)
            ."--- ВЪПРОС ---\n".$question;

        $content = $this->runCompose($company, $system, $user);
        $usage = LlmUsage::take();

        return [
            'content' => trim($content) !== '' ? trim($content) : 'Не успях да съставя отговор от интернет — опитай отново.',
            'sources' => $sources,
            'source_type' => 'web',
            'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
        ];
    }

    /** Извиква chat LLM-а в правилния контекст (cost tracking). */
    private function runCompose(Company $company, string $system, string $user, array $options = []): string
    {
        // Merge — запазваме session_id (сетнат от job-а), за да се свърже и
        // синтез-заявката със съобщението за popup-а „Детайли".
        $prev = LlmContext::get();
        LlmContext::set(array_merge($prev, ['purpose' => 'knowledge_chat', 'company_id' => $company->id]));

        try {
            return $this->composeAnswer($system, $user, $options);
        } finally {
            LlmContext::set($prev);
        }
    }

    /**
     * Надеждна ДА/НЕ проверка дали откъсите изобщо отговарят на въпроса —
     * вместо да разчитаме на фразировката на отговора (локалният модел е
     * непоследователен). НЕ / неясно → връщаме false → интернет fallback.
     */
    private function coversQuestion(Company $company, string $question, string $context): bool
    {
        $system = 'Ти си строг класификатор. Преценяваш САМО дали подадените ОТКЪСИ съдържат пряк отговор на ВЪПРОСА. Отговаряш само с една дума — ДА или НЕ. Без обяснения.';
        $user = "--- ОТКЪСИ ---\n{$context}\n--- ВЪПРОС ---\n{$question}\n\nСъдържат ли откъсите пряк отговор на въпроса? Отговори само с ДА или НЕ.";

        $verdict = mb_strtolower(trim($this->runCompose($company, $system, $user, ['num_predict' => 4, 'temperature' => 0])));

        return str_starts_with($verdict, 'да') || str_starts_with($verdict, 'yes');
    }

    /**
     * Един разговор с chat модела — ЛОКАЛЕН по подразбиране (безплатно), cloud
     * при конфигуриран chat_provider с ключ. Споделя се от двата пътя (база/уеб).
     */
    private function composeAnswer(string $system, string $user, array $optionsOverride = []): string
    {
        $provider = (string) config('services.knowledge.chat_provider', 'ollama');
        $options = array_merge(['temperature' => 0.3, 'num_predict' => 1500, 'http_timeout' => 300], $optionsOverride);

        if ($provider === 'ollama' || empty(config("services.{$provider}.api_key"))) {
            $model = (string) (config('services.knowledge.chat_model')
                ?: config('services.assist.ollama_model', 'todorov/bggpt:Gemma-3-12B-IT-Q5_K_M'));

            return app(OllamaService::class)->chat($model, $system, $user, $options);
        }

        $model = (string) (config('services.knowledge.chat_model')
            ?: config("services.{$provider}.runtime_model")
            ?: config("services.{$provider}.model"));

        return OpenAiChatService::for($provider)->chat($model, $system, $user, $options);
    }

    /**
     * Моделът сигнализира, че базата НЕ покрива въпроса → пускаме интернет.
     * Основен сигнал: NEED_WEB sentinel (по инструкция в промпта). Резервен:
     * честните "няма" фрази — ако локалният BgGPT не спази sentinel-а.
     */
    private function signalsNoCoverage(string $content): bool
    {
        $c = mb_strtolower(trim($content));

        if (str_contains($c, 'need_web')) {
            return true;
        }

        foreach ([
            'няма информация', 'няма достатъчно информация', 'не разполагам с информация',
            'няма данни', 'не са посочени', 'не съдържат', 'не е посочен', 'липсва информация',
        ] as $phrase) {
            if (str_contains($c, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** Кратка история на разговора — контекст за последващи въпроси. */
    private function historyBlock(Company $company, string $session): string
    {
        $messages = KnowledgeChatMessage::where('company_id', $company->id)
            ->where('session', $session)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit((int) config('services.knowledge.chat_history_limit', 20))
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return '';
        }

        $block = '';
        foreach ($messages as $message) {
            $line = ($message->role === 'user' ? 'Потребител: ' : 'Асистент: ')
                .mb_substr(trim((string) $message->content), 0, 600)."\n";
            if (mb_strlen($block) + mb_strlen($line) > self::HISTORY_MAX_CHARS) {
                break;
            }
            $block .= $line;
        }

        return $block === '' ? '' : "--- ДОСЕГАШЕН РАЗГОВОР ---\n{$block}\n";
    }
}
