<?php

namespace App\Agents;

use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use App\Support\PricingOutputMetrics;
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

        // Silent-truncation guard: if no explicit num_ctx is configured, Ollama falls back to
        // its tiny default (~2-4K tokens) and silently truncates large prompts, which makes the
        // model hallucinate the missing part. Size the context window to the actual prompt so the
        // model always SEES its full input. An explicit config value always wins.
        if (! isset($options['num_ctx'])) {
            $options['num_ctx'] = $this->estimateNumCtx($systemPrompt, $userMessage, $options['num_predict'] ?? null);
        }

        $this->lastChatParams = [
            'model' => $agent->model,
            'system_prompt' => $systemPrompt,
            'user_message' => $userMessage,
            'options' => $options,
            'output_language' => $agent->output_language,
            'output_tone' => $agent->output_tone,
            'output_style' => $agent->output_style,
            'output_format' => $agent->output_format,
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

    /**
     * Derive a context window large enough to hold the whole prompt plus the reply.
     * Bulgarian/Cyrillic text is roughly 2.5 characters per token; we use that ratio
     * conservatively and clamp to a sane Ollama window [8192, 32768].
     */
    protected function estimateNumCtx(string $systemPrompt, string $userMessage, int|float|null $numPredict = null): int
    {
        $inputTokens = (int) ceil((mb_strlen($systemPrompt) + mb_strlen($userMessage)) / 2.5);

        // num_predict -1 (unlimited) → reserve a generous block for the response.
        $reply = ($numPredict === null || (int) $numPredict < 0) ? 4096 : (int) $numPredict;

        $needed = $inputTokens + $reply + 1024; // headroom for chat template / safety

        return (int) max(8192, min(32768, $needed));
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Self-verification helpers (deterministic guards + targeted retry).
    //  Agents opt in via config and call chatWithSelfCheck() with a predicate.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run chat() and, while $passes($output) is false, re-run up to $maxRetries times,
     * appending $retryHint to the user message each attempt. Returns the last output.
     *
     * @param  callable(string):bool  $passes
     */
    protected function chatWithSelfCheck(
        Agent $agent,
        string $userMessage,
        callable $passes,
        string $retryHint,
        int $maxRetries = 2,
        string $extraSystemContext = ''
    ): string {
        $output = $this->chat($agent, $userMessage, $extraSystemContext);
        $maxRetries = max(0, min(3, $maxRetries));

        for ($attempt = 0; $attempt < $maxRetries && ! $passes($output); $attempt++) {
            $output = $this->chat($agent, $userMessage."\n\n".$retryHint, $extraSystemContext);
        }

        return $output;
    }

    /**
     * Detects boilerplate the small models like to hallucinate when their real input
     * was truncated (the classic "Маркетинг 2023 / example.com" fake report).
     */
    protected function looksLikePlaceholder(string $text): bool
    {
        return (bool) preg_match(
            '/example\.com|@example|lorem ipsum|отдел\s+„?\s*Маркетинг|Иван\s+Вазов["“”]?\s*123|0?2\s*123\s*45\s*67/iu',
            $text
        );
    }

    /** True when the text carries a concrete phone number or a postal-address marker. */
    protected function containsContact(string $text): bool
    {
        return (bool) preg_match('/\+359|\b0\d[\d\s\-]{6,}\d|\b(ул\.|бул\.|адрес|гр\.\s|град\s|ет\.)/iu', $text);
    }

    /** Number of markdown rows that contain a concrete numeric price. */
    protected function pricedRowCount(string $output): int
    {
        return (int) (PricingOutputMetrics::fromOutput($output)['priced_rows'] ?? 0);
    }

    /**
     * True only if every required (case-insensitive) section keyword appears in the output.
     *
     * @param  array<int, string>  $sections
     */
    protected function hasAllSections(string $output, array $sections): bool
    {
        $haystack = mb_strtolower($output);
        foreach ($sections as $section) {
            if (mb_strpos($haystack, mb_strtolower($section)) === false) {
                return false;
            }
        }

        return true;
    }
}
