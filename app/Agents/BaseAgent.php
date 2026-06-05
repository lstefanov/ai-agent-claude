<?php

namespace App\Agents;

use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use RuntimeException;

abstract class BaseAgent
{
    /** @var AgentTool[] */
    protected array $tools = [];

    protected ?string $lastRawOutput = null;

    /** @var array<string, mixed>|null Snapshot of the params sent on the last chat() call. */
    protected ?array $lastChatParams = null;

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
     * @param  array<string, mixed>  $params
     */
    protected function useTool(string $name, array $params): ?string
    {
        if (! isset($this->tools[$name])) {
            return null;
        }

        return $this->tools[$name]->execute($params);
    }

    protected function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    abstract public function run(Agent $agent, AgentRun $agentRun, array $context): string;

    public function rawOutput(): ?string
    {
        return $this->lastRawOutput;
    }

    /**
     * Snapshot of exactly what was sent to the model on the last chat() call —
     * model, final system/user prompt, options and output preferences. Recorded
     * per node_run for full run auditability.
     *
     * @return array<string, mixed>|null
     */
    public function chatParams(): ?array
    {
        return $this->lastChatParams;
    }

    protected function buildPrompt(Agent $agent, array $context): string
    {
        $prompt = $agent->prompt_template ?? '';

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace('{{'.$key.'}}', $value, $prompt);
            }
        }

        return $prompt;
    }

    protected function chat(Agent $agent, string $userMessage, string $extraSystemContext = ''): string
    {
        // system_prompt (editable in UI) takes precedence; fall back to role for older agents
        $base = ! empty($agent->system_prompt) ? $agent->system_prompt : ($agent->role ?? '');
        $systemPrompt = $base.$this->buildOutputInstructions($agent).$extraSystemContext;
        $options = $this->buildOptions($agent);

        $this->lastChatParams = [
            'model'           => $agent->model,
            'system_prompt'   => $systemPrompt,
            'user_message'    => $userMessage,
            'options'         => $options,
            'output_language' => $agent->output_language,
            'output_tone'     => $agent->output_tone,
            'output_style'    => $agent->output_style,
            'output_format'   => $agent->output_format,
        ];

        $output = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: $options
        );
        $this->lastRawOutput = $output;

        return $this->sanitizeModelOutput($output);
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
        $lines[] = 'Do not include hidden reasoning, chain-of-thought, planning notes, or <think> blocks.';
        $lines[] = 'Speak directly to the end user; do not refer to them in the third person.';
        $lines[] = 'Never write phrases like "the user wants", "I need to help the user", "той иска", "потребителят иска", or "трябва да помогна на потребителя".';

        if (! empty($agent->output_tone)) {
            $lines[] = 'Tone: Use a '.strtolower($agent->output_tone).' tone.';
        }
        if (! empty($agent->output_style)) {
            $lines[] = 'Style: Write in a '.strtolower($agent->output_style).' style.';
        }
        if (! empty($agent->output_format)) {
            $lines[] = 'Format: Structure your response as a '.strtolower($agent->output_format).'.';
        }

        return "\n\n---\nOUTPUT REQUIREMENTS:\n".implode("\n", $lines);
    }

    protected function sanitizeModelOutput(string $output): string
    {
        $cleaned = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $output);
        $cleaned = trim($cleaned ?? $output);

        if ($cleaned === '' || preg_match('/<\/?think\b/i', $cleaned)) {
            throw new RuntimeException('Model returned hidden reasoning instead of a user-facing response.');
        }

        return $cleaned;
    }

    protected function buildOptions(Agent $agent): array
    {
        $config = $agent->config ?? [];
        $options = [];

        foreach (['temperature', 'top_p', 'top_k', 'repeat_penalty', 'num_predict', 'num_ctx', 'seed'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '' && $config[$key] !== null) {
                $options[$key] = is_numeric($config[$key]) ? (float) $config[$key] : $config[$key];
            }
        }

        return $options;
    }
}
