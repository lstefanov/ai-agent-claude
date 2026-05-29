<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\LlmModel;
use Illuminate\Support\Facades\Log;

class AgentGeneratorService
{
    public function __construct(
        private OllamaService $ollama,
        private ModelSelectorService $modelSelector,
    ) {}

    public function generate(Flow $flow): array
    {
        $company       = $flow->company;
        $modelsContext = $this->buildModelsContext();

        $systemPrompt = <<<PROMPT
Ти си AI архитект на маркетингови и бизнес автоматизации.
Твоята задача: проектирай ПЪЛЕН, готов за продукция multi-agent pipeline.

СТРОГИ ПРАВИЛА:
1. Върни САМО валиден JSON масив — без markdown, без обяснения, без допълнителен текст
2. МИНИМУМ 5 агента, идеално 6-8 в зависимост от сложността
3. Всеки агент има ТОЧНО ЕДНА отговорност
4. system_prompt трябва да е детайлен (минимум 3 изречения) — на български
5. prompt_template трябва да е детайлен (минимум 5 изречения) — на български, с конкретни placeholder-и като {{company_description}}, {{input}}, {{topic}}
6. ВИНАГИ включвай: поне един researcher/analyzer, поне един content агент, точно един qa_verifier накрая
7. Избирай модели според задачата — виж списъка с модели

Генерирането на по-малко от 5 агента е ЗАБРАНЕНО.
PROMPT;

        $userMessage = <<<MSG
Компания: {$company->name}
Индустрия: {$company->industry}
Описание на компанията: {$company->description}

Flow за изграждане: "{$flow->description}"

НАЛИЧНИ МОДЕЛИ (избери внимателно за всеки агент):
{$modelsContext}

НЕОБХОДИМИ ТИПОВЕ АГЕНТИ (включи ВСИЧКИ приложими):
- researcher     → Събира контекст, тенденции, актуални новини, данни за конкуренти
- analyzer       → Анализира входа, извлича ключови инсайти, идентифицира възможности
- content_bg     → Пише текстово съдържание на български език
- content_en     → Пише текстово съдържание на английски език
- hashtag        → Генерира релевантни хаштагове (локални + международни)
- image_prompt   → Пише детайлни промпти за генериране на изображения с ComfyUI/Stable Diffusion
- caption_writer → Сглобява финалния пост от всички части (текст + хаштагове + CTA)
- translator     → Превежда съдържание между езици
- qa_verifier    → Преглежда качеството на финалния изход, оценява 0-100, ТРЯБВА да е последен агент
- summarizer     → Кондензира дълго съдържание в ключови точки
- decision       → Взима routing/условни решения
- publisher      → Форматира изхода за конкретни платформи (FB, IG, LinkedIn и др.)

ПРАВИЛА ЗА ПРОЕКТИРАНЕ НА PIPELINE:
- За social media flows: researcher → content → hashtag → image_prompt → caption_writer → qa_verifier
- За български текст: винаги използвай todorov/bggpt за генериране на текст
- За QA/верификация: използвай phi3.5 или phi3:mini (бързи, ефективни)
- За JSON/структуриран изход, image промпти, анализ: използвай mistral-nemo

Върни JSON масив, където всеки обект има ТОЧНО тези полета:
{
  "name": "Описателно българско име (напр. 'Изследовател на тенденции', 'Автор на Facebook постове')",
  "type": "един от типовете изброени по-горе",
  "role": "2-3 изречения на БЪЛГАРСКИ описващи: какво прави агентът, какъв вход получава и какъв изход произвежда",
  "capabilities": ["масив", "от", "способности"],
  "strengths": "в какво е силен агентът — на български",
  "limitations": "какво не може да прави — на български",
  "input_description": "описание на входа — на български",
  "output_description": "описание на изхода — на български",
  "system_prompt": "System prompt на БЪЛГАРСКИ. Минимум 3 изречения. Описва ролята, стила, езика и ограниченията на агента.",
  "prompt_template": "Промпт шаблон на БЪЛГАРСКИ. Минимум 5 изречения. Включи конкретни инструкции за формат, тон, дължина, какво да се включи/изключи. Използвай placeholder-и {{company_description}}, {{input}}, {{topic}} където е подходящо.",
  "model": "точен ollama tag от списъка по-горе",
  "model_reason": "защо е избран този модел — на български",
  "order": 1,
  "is_verifier": false,
  "qa_threshold": null,
  "config": {"temperature": 0.7, "num_predict": 1000}
}

За qa_verifier: is_verifier=true, qa_threshold=75, temperature=0.1
За image_prompt агенти: temperature=0.8, num_predict=500
За researcher/analyzer: temperature=0.3
MSG;

        $generatorModel = config('services.ollama.generator_model', 'mistral-nemo');

        Log::info('[AgentGenerator] Using model: ' . $generatorModel);
        Log::info('[AgentGenerator] Flow: ' . $flow->description);

        $raw = $this->ollama->chat(
            model: $generatorModel,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.2, 'num_predict' => 2000]
        );

        Log::info('[AgentGenerator] Raw response length: ' . strlen($raw));
        Log::info('[AgentGenerator] Raw response: ' . substr($raw, 0, 2000));

        $agents = $this->parseAgentJson($raw);

        Log::info('[AgentGenerator] Parsed ' . count($agents) . ' agents');

        // Safety net: if AI returned fewer than 3, something went wrong
        if (count($agents) < 3) {
            Log::warning('[AgentGenerator] Too few agents (' . count($agents) . '), returning empty to trigger retry');
            return [];
        }

        return $agents;
    }

    private function buildModelsContext(): string
    {
        // Show ALL models — not just available ones — so AI can choose
        // the best tool for each job regardless of what's currently installed.
        // Mark available ones so AI prefers them.
        $models = LlmModel::orderBy('category')->orderBy('display_name')->get();

        if ($models->isEmpty()) {
            return $this->getDefaultModelsContext();
        }

        return $models->map(function ($m) {
            $available = $m->is_available ? ' ✓ (installed)' : ' (not installed)';
            $bestFor   = $m->description ? " — {$m->description}" : '';
            return "- {$m->ollama_tag}{$available}{$bestFor}";
        })->join("\n");
    }

    private function getDefaultModelsContext(): string
    {
        return implode("\n", [
            '- todorov/bggpt ✓ (installed) — Bulgarian language text generation',
            '- mistral-nemo ✓ (installed) — JSON output, structured content, image prompts, analysis',
            '- phi3.5 ✓ (installed) — fast QA verification, simple tasks',
            '- phi3:mini ✓ (installed) — lightweight, fast responses',
            '- llama3.1:8b — general English text, summaries',
            '- mistral — JSON output, structured output',
            '- deepseek-r1:8b — reasoning, analysis, decisions',
            '- qwen2:7b — multilingual translation (29 languages)',
        ]);
    }

    private function parseAgentJson(string $raw): array
    {
        // Strip markdown code blocks if present
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $raw);
        $cleaned = trim($cleaned);

        // Find outermost JSON array
        $start = strpos($cleaned, '[');
        $end   = strrpos($cleaned, ']');

        if ($start === false || $end === false) {
            Log::error('[AgentGenerator] No JSON array found in response');
            return [];
        }

        $json   = substr($cleaned, $start, $end - $start + 1);
        $agents = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('[AgentGenerator] JSON parse error: ' . json_last_error_msg());
            Log::error('[AgentGenerator] Attempted JSON: ' . substr($json, 0, 500));

            // Try to recover truncated JSON by finding the last complete object
            $agents = $this->recoverTruncatedJson($json);
        }

        if (!is_array($agents)) {
            return [];
        }

        // Normalise and fill in defaults for each agent
        return array_values(array_filter(array_map(
            fn($a, $i) => $this->normalizeAgent($a, $i + 1),
            $agents,
            array_keys($agents)
        )));
    }

    private function recoverTruncatedJson(string $json): array
    {
        // Find the last complete } before the truncation point
        $agents   = [];
        $depth    = 0;
        $inString = false;
        $objStart = null;
        $escape   = false;

        for ($i = 0; $i < strlen($json); $i++) {
            $ch = $json[$i];

            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $inString) { $escape = true; continue; }
            if ($ch === '"') { $inString = !$inString; continue; }
            if ($inString) continue;

            if ($ch === '{') {
                if ($depth === 1) $objStart = $i;
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 1 && $objStart !== null) {
                    $objJson = substr($json, $objStart, $i - $objStart + 1);
                    $obj     = json_decode($objJson, true);
                    if (is_array($obj) && isset($obj['name'])) {
                        $agents[] = $obj;
                    }
                    $objStart = null;
                }
            }
        }

        Log::info('[AgentGenerator] Recovered ' . count($agents) . ' agents from truncated JSON');
        return $agents;
    }

    private function normalizeAgent(mixed $agent, int $fallbackOrder): ?array
    {
        if (!is_array($agent) || empty($agent['name'])) {
            return null;
        }

        return [
            'name'               => $agent['name'],
            'type'               => $agent['type'] ?? 'content',
            'role'               => $agent['role'] ?? $agent['name'],
            'capabilities'       => (array) ($agent['capabilities'] ?? []),
            'strengths'          => $agent['strengths'] ?? null,
            'limitations'        => $agent['limitations'] ?? null,
            'input_description'  => $agent['input_description'] ?? null,
            'output_description' => $agent['output_description'] ?? null,
            'prompt_template'    => $agent['prompt_template'] ?? $agent['role'] ?? '',
            'system_prompt'      => $agent['system_prompt'] ?? null,
            'model'              => $agent['model'] ?? 'mistral-nemo',
            'model_reason'       => $agent['model_reason'] ?? null,
            'order'              => (int) ($agent['order'] ?? $fallbackOrder),
            // Force qa_verifier type to always be a verifier even if AI forgot the flag
            'is_verifier'        => ($agent['type'] ?? '') === 'qa_verifier'
                ? true
                : (bool) ($agent['is_verifier'] ?? false),
            'qa_threshold'       => ($agent['type'] ?? '') === 'qa_verifier'
                ? (int) ($agent['qa_threshold'] ?? 75)
                : (isset($agent['qa_threshold']) ? (int) $agent['qa_threshold'] : null),
            'config'             => is_array($agent['config'] ?? null)
                ? $agent['config']
                : ['temperature' => 0.7, 'num_predict' => 1000],
        ];
    }
}
