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
- Ако откъсите съдържат ПОНЕ частична или свързана информация — отговори с нея (отбележи ясно ако е частична или непряка). По-добре частичен фирмен отговор, отколкото нищо.
- Цитирай източниците с номерата им в квадратни скоби, напр. [1], [2].
- Отговори САМО с думата NEED_WEB (без друг текст) единствено ако в откъсите няма АБСОЛЮТНО нищо свързано с въпроса.
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

        // Фирмено-специфичен въпрос („има ли промоции/цени/часове?") НЕ бива да
        // ходи в отворения уеб — връща конкуренти и чужди (рунет) агрегатори.
        // Затова: винаги първо собствения сайт на фирмата; отворен (но БГ-само)
        // уеб само за ОБЩИ въпроси.
        $domain = $this->companyDomain($company);
        $companySpecific = $this->isCompanySpecific($company, $question, $session);
        [$query] = $this->buildWebQuery($company, $question, $session);

        $results = [];
        try {
            // 1) Винаги първо собствения сайт на фирмата (ако има).
            if ($domain !== null) {
                $results = $this->webSearch->search($query, $this->searchOpts($company, $domain));
            }
            // 2) Само ОБЩИ въпроси разширяват до българския уеб (БЕЗ домейн филтър).
            if ($results === [] && ! $companySpecific) {
                $results = $this->webSearch->search($query, $this->searchOpts($company, null));
            }
        } catch (\Throwable $e) {
            // Perplexity недостъпен/без ключ → честен отговор, без да чупим хода.
            Log::warning('[KnowledgeChat] Web fallback failed: '.$e->getMessage());
        }

        // Защитна мрежа: махни явно чужди/нерелевантни резултати (рунет агрегатори).
        $results = $this->filterRelevantResults($results);

        if ($results === []) {
            $usage = LlmUsage::take();

            return [
                'content' => $this->noWebAnswer($company, $companySpecific),
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
- ИГНОРИРАЙ резултати, които очевидно не са за „{$company->name}" или не са на български/за България. Ако след това няма релевантни резултати — кажи честно, че няма намерена информация; НЕ изброявай чужди оферти.
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

    /**
     * Превръща въпроса (често генеричен — „има ли промоции?") в КРАТКА,
     * ФИРМЕНО-НАСОЧЕНА заявка за уеб търсене. Без анкер търсачката връща
     * нерелевантни резултати (точно тази фирма се губи). Местоименията се
     * разрешават от историята на разговора. Локален модел → безплатно.
     *
     * @return array{0: string, 1: string|null} [заявка, домейн на фирмата|null]
     */
    private function buildWebQuery(Company $company, string $question, string $session): array
    {
        $domain = $this->companyDomain($company);

        $profile = trim('Фирма: '.$company->name
            .($company->industry ? "\nБранш: ".$company->industry : '')
            .($company->description ? "\nОписание: ".mb_substr(trim((string) $company->description), 0, 300) : '')
            .($company->website_url ? "\nСайт: ".$company->website_url : ''));

        $system = 'Ти си генератор на заявки за уеб търсене (Google). Превърни въпроса на потребителя в КРАТКА заявка (3–8 думи), НАСОЧЕНА към конкретната фирма. Винаги включвай името на фирмата. Разреши местоименията и контекста от досегашния разговор. Само заявката — без кавички, без обяснения, без точка накрая.';

        $user = "--- ФИРМА ---\n{$profile}\n\n"
            .$this->historyBlock($company, $session)
            ."--- ВЪПРОС ---\n".$question."\n\nЗаявка:";

        $raw = $this->runCompose($company, $system, $user, ['num_predict' => 40, 'temperature' => 0]);

        return [$this->sanitizeQuery($raw, $company, $question), $domain];
    }

    /** Домейн на фирмата (без www) от website_url — за search_domain_filter. */
    private function companyDomain(Company $company): ?string
    {
        $url = trim((string) $company->website_url);
        if ($url === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', mb_strtolower($host));
    }

    /**
     * Изчиства LLM заявката (първи ред, без кавички/префикси/точка); гарантира
     * анкер с името на фирмата; детерминистичен fallback при празно/деградирало.
     */
    private function sanitizeQuery(string $raw, Company $company, string $question): string
    {
        $lines = preg_split('/\r?\n/', trim($raw)) ?: [];
        $q = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $q = trim($line);
                break;
            }
        }

        $q = preg_replace('/^(заявка|query)\s*[:\-—]\s*/iu', '', $q);
        $q = trim(preg_replace('/\s+/', ' ', $q), " \t\"'«»„“”.:-");

        // Празно/деградирало → детерминистичен анкер: име на фирмата + въпрос.
        if (mb_strlen($q) < 3) {
            $q = trim($company->name.' '.$question);
        }

        // Гарантирай, че името (или поне марката — първата дума) присъства.
        $name = trim((string) $company->name);
        $brand = preg_split('/[\s\-—,]+/u', $name)[0] ?? $name;
        if ($name !== '' && mb_stripos($q, $name) === false && ($brand === '' || mb_stripos($q, $brand) === false)) {
            $q = $name.' '.$q;
        }

        return mb_substr($q, 0, 200);
    }

    /**
     * Опции за Perplexity: винаги държава BG + език на фирмата (bg) — само
     * country=BG НЕ спира рунет резултатите за кирилски заявки. Домейн филтър,
     * когато ограничаваме до собствения сайт на фирмата.
     *
     * @return array<string, mixed>
     */
    private function searchOpts(Company $company, ?string $domain): array
    {
        $opts = ['country' => 'BG', 'search_language_filter' => [$this->companyLang($company)]];
        if ($domain !== null) {
            $opts['search_domain_filter'] = [$domain];
        }

        return $opts;
    }

    /** Език на фирмата за search_language_filter (по подразбиране bg). */
    private function companyLang(Company $company): string
    {
        return mb_strtolower(trim((string) ($company->language ?: 'bg'))) ?: 'bg';
    }

    /**
     * Фирмено-специфичен ли е въпросът (услуги/цени/промоции/часове/контакти/
     * записване/локации) или ОБЩ (определение, как действа нещо, сравнения)?
     * Фирмените въпроси НЕ ходят в отворения уеб. При неяснота → фирмен (по-
     * безопасно). Евтин локален класификатор, като coversQuestion().
     */
    private function isCompanySpecific(Company $company, string $question, string $session): bool
    {
        $system = 'Ти си класификатор. Ако въпросът се отнася за КОНКРЕТНАТА фирма — нейните услуги, цени, промоции, работно време, контакти, записване на час, локации — отговори ФИРМА. Ако е ОБЩ въпрос (определение, как действа процедура, общи съвети, сравнения), отговори ОБЩ. Само една дума, без обяснения.';
        $user = "Фирма: {$company->name}".($company->industry ? " ({$company->industry})" : '')."\n"
            .$this->historyBlock($company, $session)
            ."--- ВЪПРОС ---\n".$question."\n\nФИРМА или ОБЩ?";

        $verdict = mb_strtolower(trim($this->runCompose($company, $system, $user, ['num_predict' => 4, 'temperature' => 0])));

        // Само явно „ОБЩ" пуска отворения уеб; всичко друго остава фирмено.
        return ! str_starts_with($verdict, 'общ');
    }

    /**
     * Защитна мрежа след търсенето: махни явно чужди/нерелевантни резултати
     * (рунет агрегатори, чужди TLD) — език филтърът на търсачката не е гаранция.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function filterRelevantResults(array $results): array
    {
        $denyHosts = ['pikabu', 'promokod', 'promokodus', 'telega.in', 'telemetr', 't.me', 'hh.ru', 'avito', 'ozon', 'wildberries'];

        return array_values(array_filter($results, function ($r) use ($denyHosts) {
            $host = mb_strtolower((string) parse_url((string) ($r['url'] ?? ''), PHP_URL_HOST));
            if ($host === '') {
                return false;
            }
            $host = preg_replace('/^www\./', '', $host);
            if (preg_match('/\.(ru|by|kz|su)$/', $host)) {
                return false;
            }
            foreach ($denyHosts as $deny) {
                if (str_contains($host, $deny)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Честен отговор, когато няма нито фирмени, нито надеждни уеб резултати. */
    private function noWebAnswer(Company $company, bool $companySpecific): string
    {
        if ($companySpecific) {
            $site = $company->website_url ? " ({$company->website_url})" : '';

            return "Не намерих информация по този въпрос в базата знания на „{$company->name}“, нито на официалния ѝ сайт{$site}. "
                .'Възможно е да е публикувано в социалните им мрежи (Facebook/Instagram) или да го уточните по телефона. '
                .'Можете също да добавите ресурс по темата в базата знания, за да отговарям занапред.';
        }

        return 'Не намерих надеждна информация на български по този въпрос.';
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
        $system = 'Ти си класификатор. Преценяваш дали подадените ОТКЪСИ съдържат информация, която помага да се отговори на ВЪПРОСА — дори частично или непряко (свързана услуга, цена, условие, промоция). Отговаряш само с една дума — ДА или НЕ. Без обяснения.';
        $user = "--- ОТКЪСИ ---\n{$context}\n--- ВЪПРОС ---\n{$question}\n\nПомагат ли откъсите да се отговори на въпроса (дори частично)? Отговори само с ДА или НЕ.";

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
