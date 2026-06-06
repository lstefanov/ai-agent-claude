<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class ContentAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $check = ($agent->config ?? [])['self_check'] ?? null;

        if (! is_array($check)) {
            return $this->chat($agent, $agentRun->input);
        }

        // Deterministic self-verification for report-style agents: re-run with a
        // corrective hint if the output is degenerate (too short — run 71's "Благо"),
        // hallucinates boilerplate, or is missing a required section.
        $sections = array_values(array_filter((array) ($check['required_sections'] ?? [])));
        $minChars = (int) ($check['min_output_chars'] ?? 0);
        $noPlaceholder = (bool) ($check['no_placeholder'] ?? false);
        $maxRetries = (int) ($check['max_retries'] ?? 1);

        $passes = function (string $out) use ($sections, $minChars, $noPlaceholder): bool {
            if ($minChars > 0 && mb_strlen(trim($out)) < $minChars) {
                return false;
            }
            if ($noPlaceholder && $this->looksLikePlaceholder($out)) {
                return false;
            }
            if ($sections && ! $this->hasAllSections($out, $sections)) {
                return false;
            }

            return true;
        };

        $hint = 'Предходният опит беше непълен, изроден или съдържаше шаблонни данни. '
            .'Върни ПЪЛНИЯ доклад на български с ВСИЧКИ задължителни раздели и с РЕАЛНИТЕ данни от входа '
            .'(услуги с цени, контакти, ревюта, препоръки). Без примерни/шаблонни данни (никакви example.com, '
            .'„отдел Маркетинг" или измислени телефони).';

        return $this->chatWithSelfCheck($agent, $agentRun->input, $passes, $hint, $maxRetries);
    }
}
