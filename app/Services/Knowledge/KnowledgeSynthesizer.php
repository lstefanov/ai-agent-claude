<?php

namespace App\Services\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeFact;
use App\Models\WebPageDigest;
use App\Services\OpenAiChatService;
use App\Services\WebPageCacheService;
use App\Support\LlmContext;
use App\Support\PageContent;
use Illuminate\Support\Facades\Log;

/**
 * LLM синтез при ingest: от сурова страница/документ прави (а) структуриран
 * digest — "извлечената информация", която UI-ът показва и търсенето чете, и
 * (б) списък ФАКТИ за фирмения профил (категория, локация, име, стойност,
 * confidence).
 *
 * Провайдърът е евтин/безплатен cloud (services.knowledge.synth_provider,
 * default gemini flash-lite — free tier). Страничните синтези се кешират
 * ГЛОБАЛНО в web_page_digests по (url, content_hash, промпт версия) — при
 * повторно обхождане непроменена страница не струва нито един LLM call.
 *
 * Никога не хвърля: при липсващ ключ/грешка пада към съкратено сурово
 * съдържание без факти (ingest-ът никога не се проваля заради синтеза).
 */
class KnowledgeSynthesizer
{
    /** Участва в params_hash — нова версия на промпта инвалидира digest кеша. */
    private const PROMPT_VERSION = 2;

    private const MAX_INPUT_CHARS = 14000;

    private const MAX_FACTS = 40;

    public function __construct(private WebPageCacheService $cache) {}

    /**
     * Синтез на ОБХОДЕНА СТРАНИЦА — с глобален digest reuse по content_hash.
     *
     * @return array{digest: string, facts: array<int, array<string, mixed>>, reused: bool}
     */
    public function synthesizePage(
        Company $company,
        string $url,
        ?string $title,
        ?string $metaDescription,
        string $markdown,
        array $llmContext = [],
        string $audience = 'own',
    ): array {
        $urlHash = $this->cache->urlHash($url) ?? hash('sha256', $url);
        $contentHash = hash('sha256', trim($markdown));

        if (($cached = $this->cachedDigest($urlHash, $contentHash, $audience)) !== null) {
            return $cached + ['reused' => true];
        }

        $context = "URL: {$url}";
        if ($title) {
            $context .= "\nЗаглавие: {$title}";
        }
        if ($metaDescription) {
            $context .= "\nMeta описание: {$metaDescription}";
        }

        $result = $this->synthesize(
            $company,
            $context,
            PageContent::stripBoilerplate($markdown),
            $llmContext,
            $audience,
        );

        if ($result['llm']) {
            $this->storeDigest($urlHash, $contentHash, $result, $audience);
        }

        return ['digest' => $result['digest'], 'facts' => $result['facts'], 'reused' => false];
    }

    /**
     * Синтез на ДОКУМЕНТ/БЕЛЕЖКА/СНИМКА (извлечен текст). Кешира се по
     * съдържание (псевдо url_hash от content hash) — re-ingest на непроменен
     * файл е безплатен.
     *
     * @return array{digest: string, facts: array<int, array<string, mixed>>, reused: bool}
     */
    public function synthesizeDocument(
        Company $company,
        string $title,
        string $text,
        array $llmContext = [],
        string $audience = 'own',
    ): array {
        $contentHash = hash('sha256', trim($text));
        $urlHash = hash('sha256', 'doc:'.$contentHash);

        if (($cached = $this->cachedDigest($urlHash, $contentHash, $audience)) !== null) {
            return $cached + ['reused' => true];
        }

        $result = $this->synthesize($company, "Документ: {$title}", $text, $llmContext, $audience);

        if ($result['llm']) {
            $this->storeDigest($urlHash, $contentHash, $result, $audience);
        }

        return ['digest' => $result['digest'], 'facts' => $result['facts'], 'reused' => false];
    }

    /**
     * Извличане САМО на факти (без digest) — за изходите на агентите след
     * успешен run. Агентският текст може да съдържа и генерирано съдържание,
     * затова промптът изисква консервативност: само твърдения, представени
     * като реални данни за фирмата.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractFacts(Company $company, string $sourceContext, string $content, array $llmContext = []): array
    {
        $content = trim(mb_substr(trim($content), 0, self::MAX_INPUT_CHARS));
        $provider = $this->provider();

        if ($provider === null || $content === '') {
            return [];
        }

        $categories = implode('|', KnowledgeFact::CATEGORIES);

        $system = <<<PROMPT
Ти си екстрактор на фирмени знания. Получаваш ИЗХОДИ ОТ AI АГЕНТИ, работили по задача за фирмата "{$company->name}". Извлечи само КОНКРЕТНИТЕ, проверими факти за самата фирма (category: {$categories}): услуги, цени, контакти, локации, условия, конкуренти.

Правила:
- name: кратко нормализирано име на факта; value: пълната стойност с валута/единици;
- location: град/обект, ако фактът важи само за конкретна локация (иначе null);
- БЪДИ КОНСЕРВАТИВЕН: текстът съдържа и ГЕНЕРИРАНО съдържание (постове, идеи, предложения) — то НЕ е факт. Взимай само твърдения, представени като реални данни за фирмата (извлечени от сайта ѝ, ревюта, документи);
- confidence: 0–1 (генерирано/несигурно → под 0.5, то ще бъде отхвърлено);
- нищо измислено; празен списък е валиден отговор.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => KnowledgeFact::CATEGORIES],
                            'location' => ['type' => ['string', 'null']],
                            'name' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['category', 'location', 'name', 'value', 'confidence'],
                    ],
                ],
            ],
            'required' => ['facts'],
        ];

        LlmContext::push(array_merge(['purpose' => 'knowledge_fact_harvest'], $llmContext));

        try {
            $json = OpenAiChatService::for($provider)->chatJson(
                $this->model(),
                $system,
                $sourceContext."\n\n--- ИЗХОДИ ---\n".$content,
                'knowledge_facts',
                $schema,
                ['temperature' => 0.1, 'num_predict' => 2500, 'http_timeout' => 180],
            );

            return $this->sanitizeFacts((array) ($json['facts'] ?? []));
        } catch (\Throwable $e) {
            Log::warning('[KnowledgeSynthesizer] Fact extraction failed ('.$provider.'): '.$e->getMessage());

            return [];
        } finally {
            LlmContext::pop();
        }
    }

    public function provider(): ?string
    {
        $provider = (string) config('services.knowledge.synth_provider', 'gemini');

        if ($provider === '' || empty(config("services.{$provider}.api_key"))) {
            return null; // няма ключ → fallback без LLM
        }

        return $provider;
    }

    public function model(): string
    {
        $provider = $this->provider() ?? 'gemini';

        return (string) (config('services.knowledge.synth_model')
            ?: config("services.{$provider}.runtime_model")
            ?: config("services.{$provider}.model", ''));
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @return array{digest: string, facts: array<int, array<string, mixed>>, llm: bool} */
    private function synthesize(Company $company, string $sourceContext, string $content, array $llmContext, string $audience = 'own'): array
    {
        $content = trim(mb_substr(trim($content), 0, self::MAX_INPUT_CHARS));
        $provider = $this->provider();

        if ($provider === null || $content === '') {
            return ['digest' => mb_substr($content, 0, 4000), 'facts' => [], 'llm' => false];
        }

        $categories = implode('|', KnowledgeFact::CATEGORIES);

        // Външни/пазарни данни (конкуренти, уеб-проучване) НЕ бива да стават факти
        // за самата фирма — синтезираме ги като данни за КОНКУРЕНТИ/пазара.
        $system = $audience === 'external'
            ? <<<PROMPT
Ти си анализатор на ВЪНШНА/пазарна информация за фирмата "{$company->name}". Съдържанието е за КОНКУРЕНТИ и пазара — НЕ за самата фирма "{$company->name}".

Върни:
1. "digest" — синтезирано, плътно markdown резюме НА БЪЛГАРСКИ на полезната външна информация: конкурентни центрове/услуги/цени, пазарни данни, оферти. Не добавяй нищо, което го няма в съдържанието. Без увод и без коментари за задачата.
2. "facts" — конкретни факти за КОНКУРЕНТИ/пазара. ЗАДЪЛЖИТЕЛНО category: "competitors" (или "other", ако не е за конкретен конкурент). Правила:
   - name: кратко име (напр. "цена месечен абонамент - конкурент X");
   - value: пълната стойност с валута/единици;
   - location: град/обект, ако важи за конкретно място (иначе null);
   - confidence: 0–1;
   - САМО явно посочени факти. НИКОГА не приписвай тези данни на самата фирма "{$company->name}".
PROMPT
            : <<<PROMPT
Ти си екстрактор на фирмени знания. Получаваш съдържанието на една страница/документ, свързани с фирмата "{$company->name}".

Върни:
1. "digest" — синтезирано, плътно markdown резюме НА БЪЛГАРСКИ на цялата полезна информация: за какво е страницата/документът, услуги/продукти С ЦЕНИТЕ ИМ, условия, контакти, локации. ЗАПАЗИ таблиците с допълнителна информация (брой процедури, сесии, зони, повтаряемост и т.н.) като markdown таблици/списъци. Не добавяй нищо, което го няма в съдържанието. Без увод и без коментари за самата задача.
2. "facts" — списък от конкретни, проверими факти за фирмата (category: {$categories}). Правила:
   - name: кратко нормализирано име на факта (напр. "цена лазерна епилация подмишници мъже");
   - value: пълната стойност с валута/мерни единици (напр. "60 лв. единична процедура, пакет 6 броя — 300 лв.");
   - location: град/обект, ако фактът важи само за конкретна локация (иначе null);
   - confidence: 0–1 колко сигурно съдържанието твърди факта;
   - САМО явно посочени факти — нищо измислено; без общи приказки.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'digest' => ['type' => 'string'],
                'facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => KnowledgeFact::CATEGORIES],
                            'location' => ['type' => ['string', 'null']],
                            'name' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['category', 'location', 'name', 'value', 'confidence'],
                    ],
                ],
            ],
            'required' => ['digest', 'facts'],
        ];

        LlmContext::push(array_merge(['purpose' => 'knowledge_synthesis'], $llmContext));

        try {
            $json = OpenAiChatService::for($provider)->chatJson(
                $this->model(),
                $system,
                $sourceContext."\n\n--- СЪДЪРЖАНИЕ ---\n".$content,
                'knowledge_synthesis',
                $schema,
                ['temperature' => 0.2, 'num_predict' => 3000, 'http_timeout' => 180],
            );

            return [
                'digest' => mb_substr(trim((string) ($json['digest'] ?? '')), 0, 12000),
                'facts' => $this->sanitizeFacts((array) ($json['facts'] ?? []), $audience),
                'llm' => true,
            ];
        } catch (\Throwable $e) {
            Log::warning('[KnowledgeSynthesizer] LLM synthesis failed ('.$provider.'): '.$e->getMessage());

            return ['digest' => mb_substr($content, 0, 4000), 'facts' => [], 'llm' => false];
        } finally {
            LlmContext::pop();
        }
    }

    /**
     * Планерът/LLM-ът ПРЕДЛАГА, кодът ГАРАНТИРА: категориите се клампват към
     * enum-а, confidence към [0,1], празни факти отпадат.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeFacts(array $raw, string $audience = 'own'): array
    {
        $facts = [];
        foreach ($raw as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $name = trim((string) ($fact['name'] ?? ''));
            $value = trim((string) ($fact['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }

            $category = (string) ($fact['category'] ?? 'other');
            $category = in_array($category, KnowledgeFact::CATEGORIES, true) ? $category : 'other';

            // Външни данни никога не стават факти за собствената фирма — клампваме
            // към competitors/other, каквото и да е предложил LLM-ът.
            if ($audience === 'external' && ! in_array($category, ['competitors', 'other'], true)) {
                $category = 'competitors';
            }

            $location = trim((string) ($fact['location'] ?? ''));

            $facts[] = [
                'category' => $category,
                'location' => $location !== '' ? mb_substr($location, 0, 150) : null,
                'name' => mb_substr($name, 0, 300),
                'value' => mb_substr($value, 0, 4000),
                'confidence' => max(0.0, min(1.0, (float) ($fact['confidence'] ?? 0.5))),
            ];

            if (count($facts) >= self::MAX_FACTS) {
                break;
            }
        }

        return $facts;
    }

    private function paramsHash(string $audience = 'own'): string
    {
        $suffix = $audience === 'external' ? '|ext' : '';

        return hash('sha256', $this->model().'|kb|v'.self::PROMPT_VERSION.$suffix);
    }

    /** @return array{digest: string, facts: array<int, array<string, mixed>>}|null */
    private function cachedDigest(string $urlHash, string $contentHash, string $audience = 'own'): ?array
    {
        $row = WebPageDigest::where('url_hash', $urlHash)
            ->where('content_hash', $contentHash)
            ->where('params_hash', $this->paramsHash($audience))
            ->first();

        if (! $row) {
            return null;
        }

        $payload = json_decode($row->digest, true);
        if (! is_array($payload) || trim((string) ($payload['digest'] ?? '')) === '') {
            return null;
        }

        $row->increment('hit_count');

        return [
            'digest' => (string) $payload['digest'],
            'facts' => $this->sanitizeFacts((array) ($payload['facts'] ?? []), $audience),
        ];
    }

    /** @param array{digest: string, facts: array<int, array<string, mixed>>} $result */
    private function storeDigest(string $urlHash, string $contentHash, array $result, string $audience = 'own'): void
    {
        try {
            WebPageDigest::updateOrCreate(
                [
                    'url_hash' => $urlHash,
                    'content_hash' => $contentHash,
                    'params_hash' => $this->paramsHash($audience),
                ],
                [
                    'model' => mb_substr($this->model(), 0, 100),
                    'digest' => json_encode(
                        ['digest' => $result['digest'], 'facts' => $result['facts']],
                        JSON_UNESCAPED_UNICODE,
                    ) ?: '',
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
