<?php

namespace App\Services\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeChatMessage;
use App\Services\KnowledgeService;
use App\Services\OllamaService;
use App\Services\OpenAiChatService;
use App\Support\LlmContext;
use App\Support\LlmUsage;

/**
 * Чатът "Тествай знанията": въпрос на човешки език → hybrid retrieval върху
 * базата знания (чанкове + факти) → LLM съставя отговор на български с
 * цитирани източници. ЛОКАЛЕН модел по подразбиране (безплатни въпроси,
 * services.knowledge.chat_provider=ollama → BgGPT); cloud при нужда.
 *
 * Слабо покритие → отговаря честно "няма в базата" И логва "пропуск" — точно
 * както когато агент удари на камък.
 */
class KnowledgeChatService
{
    private const CONTEXT_MAX_CHARS = 7000;

    private const HISTORY_MAX_CHARS = 3000;

    public function __construct(private KnowledgeService $knowledge) {}

    /**
     * @return array{content: string, sources: array<int, array<string, mixed>>, cost_usd: float}
     */
    public function turn(Company $company, string $question, string $session, ?callable $onStage = null): array
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

        $onStage && $onStage('Съставям отговор…');

        $system = <<<PROMPT
Ти си асистентът на базата знания на фирма "{$company->name}". Отговаряш на въпроси САМО въз основа на подадените ОТКЪСИ от базата знания.

Правила:
- Отговаряй на български, ясно и структурирано (markdown: списъци/таблици при цени и условия).
- Цените, числата и условията предавай ТОЧНО както са в откъсите — нищо измислено.
- Когато въпросът не уточнява вариант (напр. пол мъже/жени), покажи ВСИЧКИ налични варианти от откъсите.
- Свързвай информацията от няколко откъса в един цялостен отговор (описание + цени + допълнителна информация).
- Цитирай източниците с номерата им в квадратни скоби, напр. [1], [2].
- Ако в откъсите НЯМА достатъчно информация за въпроса, кажи го честно ("В базата знания няма информация за …") и не си измисляй.
PROMPT;

        $user = ($context !== ''
                ? "--- ОТКЪСИ ОТ БАЗАТА ЗНАНИЯ ---\n{$context}"
                : "--- БАЗАТА ЗНАНИЯ НЕ ВЪРНА НИЩО РЕЛЕВАНТНО ---\n\n")
            .$this->historyBlock($company, $session)
            ."--- ВЪПРОС ---\n".$question;

        LlmContext::set(['purpose' => 'knowledge_chat', 'company_id' => $company->id]);

        try {
            $provider = (string) config('services.knowledge.chat_provider', 'ollama');
            $options = ['temperature' => 0.3, 'num_predict' => 1500, 'http_timeout' => 300];

            if ($provider === 'ollama' || empty(config("services.{$provider}.api_key"))) {
                $model = (string) (config('services.knowledge.chat_model')
                    ?: config('services.assist.ollama_model', 'todorov/bggpt:Gemma-3-12B-IT-Q5_K_M'));
                $content = app(OllamaService::class)->chat($model, $system, $user, $options);
            } else {
                $model = (string) (config('services.knowledge.chat_model')
                    ?: config("services.{$provider}.runtime_model")
                    ?: config("services.{$provider}.model"));
                $content = OpenAiChatService::for($provider)->chat($model, $system, $user, $options);
            }
        } finally {
            LlmContext::clear();
        }

        $usage = LlmUsage::take();

        return [
            'content' => trim($content) !== '' ? trim($content) : 'Не успях да съставя отговор — опитай отново.',
            'sources' => $sources,
            'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
        ];
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
