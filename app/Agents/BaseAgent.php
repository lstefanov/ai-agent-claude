<?php

namespace App\Agents;

use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;

abstract class BaseAgent
{
    /** @var AgentTool[] */
    protected array $tools = [];

    public function __construct(
        protected OllamaService $ollama,
        array $tools = []
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Execute a registered tool by name. Returns null if the tool is not registered.
     *
     * @param array<string, mixed> $params
     */
    protected function useTool(string $name, array $params): ?string
    {
        if (!isset($this->tools[$name])) {
            return null;
        }

        return $this->tools[$name]->execute($params);
    }

    protected function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    abstract public function run(Agent $agent, AgentRun $agentRun, array $context): string;

    protected function buildPrompt(Agent $agent, array $context): string
    {
        $prompt = $agent->prompt_template ?? '';

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
            }
        }

        return $prompt;
    }

    protected function chat(Agent $agent, string $userMessage, string $extraSystemContext = ''): string
    {
        $systemPrompt = $agent->role . $this->buildOutputInstructions($agent) . $extraSystemContext;

        return $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: $this->buildOptions($agent)
        );
    }

    protected function buildOutputInstructions(Agent $agent): string
    {
        $lines = [];

        $langMap = [
            'bg' => 'Bulgarian', 'en' => 'English', 'de' => 'German',
            'fr' => 'French',    'es' => 'Spanish', 'ru' => 'Russian',
        ];
        $lang = $langMap[$agent->output_language ?? 'bg'] ?? ($agent->output_language ?? 'Bulgarian');
        $lines[] = "Language: Always respond in {$lang}.";

        if (!empty($agent->output_tone)) {
            $lines[] = "Tone: Use a " . strtolower($agent->output_tone) . " tone.";
        }
        if (!empty($agent->output_style)) {
            $lines[] = "Style: Write in a " . strtolower($agent->output_style) . " style.";
        }
        if (!empty($agent->output_format)) {
            $lines[] = "Format: Structure your response as a " . strtolower($agent->output_format) . ".";
        }

        return "\n\n---\nOUTPUT REQUIREMENTS:\n" . implode("\n", $lines);
    }

    protected function buildOptions(Agent $agent): array
    {
        $config  = $agent->config ?? [];
        $options = [];

        foreach (['temperature', 'top_p', 'top_k', 'repeat_penalty', 'num_predict'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '' && $config[$key] !== null) {
                $options[$key] = is_numeric($config[$key]) ? (float) $config[$key] : $config[$key];
            }
        }

        return $options;
    }
}
