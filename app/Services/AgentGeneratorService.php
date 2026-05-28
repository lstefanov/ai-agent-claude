<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\LlmModel;

class AgentGeneratorService
{
    public function __construct(
        private OllamaService $ollama,
        private ModelSelectorService $modelSelector,
    ) {}

    public function generate(Flow $flow): array
    {
        $company = $flow->company;
        $modelsContext = $this->buildModelsContext();

        $systemPrompt = <<<PROMPT
Ти си AI архитект на агентни системи. Анализираш описания на бизнес процеси
и проектираш минималния необходим набор от специализирани агенти.
Всеки агент има ЕДИНСТВЕНА отговорност. Включваш ЗАДЪЛЖИТЕЛНО QA агент.
Избираш модела спрямо задачата и езика на фирмата.
Връщаш САМО валиден JSON масив без никакъв друг текст или markdown.
PROMPT;

        $userMessage = <<<MSG
Фирма: {$company->name}
Сектор: {$company->industry}
Език: {$company->language}
Описание на фирмата: {$company->description}

Flow описание: "{$flow->description}"

Налични модели:
{$modelsContext}

Върни САМО валиден JSON масив с агентите. Всеки агент трябва да има:
name (string), type (string), role (string), capabilities (array of strings),
strengths (string), limitations (string), input_description (string),
output_description (string), prompt_template (string), model (string),
model_reason (string), order (int, започвай от 1), is_verifier (bool),
qa_threshold (int 0-100 или null), config (object с temperature и max_tokens)

Типове агенти: content_bg, content_en, image_prompt, qa_verifier, analyzer,
researcher, summarizer, decision, publisher, translator, code, vision, orchestrator
MSG;

        $generatorModel = config('services.ollama.generator_model', 'mistral');

        $raw = $this->ollama->chat(
            model: $generatorModel,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.3]
        );

        return $this->parseAgentJson($raw);
    }

    private function buildModelsContext(): string
    {
        $models = LlmModel::where('is_available', true)->get();

        if ($models->isEmpty()) {
            return $this->getDefaultModelsContext();
        }

        return $models->map(fn($m) => "- {$m->ollama_tag}: {$m->description}")->join("\n");
    }

    private function getDefaultModelsContext(): string
    {
        return implode("\n", [
            '- todorov/bggpt: текст на български език',
            '- llama3.1:8b: генерален английски текст, резюмета',
            '- mistral: JSON output, инструкции, структуриран output, image prompts',
            '- deepseek-r1:8b: reasoning, анализи, вземане на решения',
            '- phi3.5:mini: бърза QA проверка',
            '- phi3:mini: прости публикуващи задачи',
            '- qwen2.5:7b: structured tasks, многоезичност, research',
            '- qwen2:7b: многоезичен превод (29 езика)',
            '- llama3.2-vision:11b: анализ на изображения',
            '- deepseek-coder-v2: генериране на код',
        ]);
    }

    private function parseAgentJson(string $raw): array
    {
        // Strip markdown code blocks
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $raw);
        $cleaned = trim($cleaned);

        // Find JSON array boundaries
        $start = strpos($cleaned, '[');
        $end   = strrpos($cleaned, ']');

        if ($start === false || $end === false) {
            return [];
        }

        $json   = substr($cleaned, $start, $end - $start + 1);
        $agents = json_decode($json, true);

        return is_array($agents) ? $agents : [];
    }
}
