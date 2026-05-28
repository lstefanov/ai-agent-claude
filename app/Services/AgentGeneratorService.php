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
You are an AI pipeline architect for marketing and business automation.
Your job: design a COMPLETE, production-ready multi-agent pipeline.

STRICT RULES:
1. Return ONLY a valid JSON array — no markdown, no explanations, no extra text
2. MINIMUM 5 agents, ideally 6-8 depending on complexity
3. Every agent has exactly ONE responsibility
4. prompt_template must be detailed and specific (minimum 4 sentences)
5. ALWAYS include: at least one researcher/analyzer, at least one content agent, at least one image_prompt agent for visual content flows, exactly one qa_verifier at the end
6. Choose models based on task and language — see the model list

You MUST generate a complete pipeline. Returning fewer than 5 agents is FORBIDDEN.
PROMPT;

        $userMessage = <<<MSG
Company: {$company->name}
Industry: {$company->industry}
Language: {$company->language}
Company description: {$company->description}

Flow to build: "{$flow->description}"

AVAILABLE MODELS (choose wisely for each agent):
{$modelsContext}

REQUIRED AGENT TYPES for this pipeline (include ALL that apply):
- researcher     → Collects context, trends, current events, competitor data
- analyzer       → Analyzes input, extracts key insights, identifies opportunities
- content_bg     → Writes Bulgarian-language text content
- content_en     → Writes English-language text content
- hashtag        → Generates relevant hashtags (local + international)
- image_prompt   → Writes detailed ComfyUI/Stable Diffusion image generation prompts
- caption_writer → Assembles final post from all parts (text + hashtags + CTA)
- translator     → Translates content between languages
- qa_verifier    → Reviews final output quality, scores 0-100, MUST be last agent
- summarizer     → Condenses long content into key points
- decision       → Makes routing/conditional decisions
- publisher      → Formats output for specific platforms (FB, IG, LinkedIn, etc.)

PIPELINE DESIGN RULES:
- For social media flows: researcher → content → hashtag → image_prompt → caption_writer → qa_verifier
- For Bulgarian content: always use todorov/bggpt for text generation
- For QA/verification: use phi3.5 or phi3:mini (fast, efficient)
- For JSON/structured output, image prompts, analysis: use mistral-nemo

Return a JSON array where each object has EXACTLY these fields:
{
  "name": "snake_case_name",
  "type": "one of the types listed above",
  "role": "one-sentence job description",
  "capabilities": ["array", "of", "capabilities"],
  "strengths": "what this agent excels at",
  "limitations": "what it cannot do",
  "input_description": "what input it receives",
  "output_description": "what output it produces",
  "prompt_template": "Detailed system prompt. At least 4 sentences. Include specific instructions about format, tone, language, what to include/exclude.",
  "model": "exact ollama tag from the list above",
  "model_reason": "why this model was chosen",
  "order": 1,
  "is_verifier": false,
  "qa_threshold": null,
  "config": {"temperature": 0.7, "num_predict": 1000}
}

For qa_verifier: set is_verifier=true, qa_threshold=75, temperature=0.1
For image_prompt agents: temperature=0.8, num_predict=500
For researcher/analyzer: temperature=0.3
MSG;

        $generatorModel = config('services.ollama.generator_model', 'mistral-nemo');

        Log::info('[AgentGenerator] Using model: ' . $generatorModel);
        Log::info('[AgentGenerator] Flow: ' . $flow->description);

        $raw = $this->ollama->chat(
            model: $generatorModel,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.2, 'num_predict' => 4000]
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
            'model'              => $agent['model'] ?? 'mistral-nemo',
            'model_reason'       => $agent['model_reason'] ?? null,
            'order'              => (int) ($agent['order'] ?? $fallbackOrder),
            'is_verifier'        => (bool) ($agent['is_verifier'] ?? false),
            'qa_threshold'       => isset($agent['qa_threshold']) ? (int) $agent['qa_threshold'] : null,
            'config'             => is_array($agent['config'] ?? null)
                ? $agent['config']
                : ['temperature' => 0.7, 'num_predict' => 1000],
        ];
    }
}
