<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class ReportComposerAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $companyName = $context['company_name'] ?? 'Company';
        $companyDescription = $context['company_description'] ?? '';
        $config = $agent->config ?? [];

        $profileText = $this->contextValue($context, $config['profile_source'] ?? null)
            ?? $this->firstMatchingContext($context, ['профил', 'profile', 'скрапер', 'competitor']);
        $tableText = $this->contextValue($context, $config['table_source'] ?? null)
            ?? $this->firstMatchingContext($context, ['цени', 'ценова', 'екстрактор', 'price', 'table']);
        $analysisText = $this->contextValue($context, $config['analysis_source'] ?? null)
            ?? $this->firstMatchingContext($context, ['анализ', 'analysis', 'trend']);

        $tables = $this->extractMarkdownTables((string) $tableText);
        if ($tables === [] && is_string($tableText) && trim($tableText) !== '') {
            $tables[] = trim($tableText);
        }

        $narrative = $this->buildNarrative(
            $agent,
            (string) $companyName,
            (string) $companyDescription,
            (string) $profileText,
            (string) $analysisText
        );
        $rawNarrative = $this->lastRawOutput;

        $report = trim(implode("\n\n", array_filter([
            "# Конкурентен доклад — {$companyName}",
            $companyDescription !== '' ? "**Контекст:** {$companyDescription}" : null,
            "## 1. Ценово разузнаване\n\n".($tables !== [] ? implode("\n\n", $tables) : 'Няма открити структурирани ценови таблици.'),
            $profileText ? "## 2. Профили на конкуренти\n\n".trim((string) $profileText) : null,
            "## 3. Анализ и препоръки\n\n".$narrative,
        ])));

        $this->lastRawOutput = ($rawNarrative !== null && $rawNarrative !== $narrative)
            ? str_replace($narrative, $rawNarrative, $report)
            : null;

        return $report;
    }

    private function buildNarrative(Agent $agent, string $companyName, string $companyDescription, string $profileText, string $analysisText): string
    {
        $systemPrompt = <<<'PROMPT'
You write only the narrative analysis and recommendations for a Bulgarian competitive pricing report.
Rules:
- Respond in Bulgarian.
- Do not include hidden reasoning or <think> blocks.
- Do not recreate markdown tables.
- Use only the provided competitor profiles and analysis.
- Be concrete: mention prices only when they appear in the provided text.
PROMPT;

        $userMessage = <<<PROMPT
Company: {$companyName}
Description: {$companyDescription}

Competitor profiles:
{$profileText}

Existing analysis:
{$analysisText}

Write 5 concrete management recommendations plus a short positioning summary.
PROMPT;

        $raw = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: $this->buildOptions($agent)
        );

        $this->lastRawOutput = $raw;

        return $this->sanitizeModelOutput($raw);
    }

    private function contextValue(array $context, ?string $key): ?string
    {
        if (! $key || ! isset($context[$key]) || ! is_string($context[$key])) {
            return null;
        }

        return $context[$key];
    }

    private function firstMatchingContext(array $context, array $needles): ?string
    {
        foreach ($context as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $lowerKey = mb_strtolower((string) $key);
            foreach ($needles as $needle) {
                if (str_contains($lowerKey, mb_strtolower($needle))) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractMarkdownTables(string $text): array
    {
        preg_match_all('/(?:^|\n)(\|[^\n]+\|\n\|[\s:\-|]+\|\n(?:\|[^\n]+\|\n?)+)/m', $text, $matches);

        return array_values(array_filter(array_map('trim', $matches[1] ?? [])));
    }
}
