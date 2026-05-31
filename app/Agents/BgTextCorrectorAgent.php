<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class BgTextCorrectorAgent extends BaseAgent
{
    private const SYSTEM_KEYS = [
        'company_description', 'company_name', 'company_industry',
        'input', 'topic', 'flow_topic',
    ];

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $textToCorrect = $this->findBodyContent($context);

        if ($textToCorrect === '') {
            return $this->chat($agent, $agentRun->input);
        }

        $prompt = "Прегледай следния текст и поправи САМО правописните грешки на кирилица. "
            . "НЕ преструктурирай, НЕ пренаписвай, НЕ добавяй информация. "
            . "Върни точно същия текст с поправен правопис:\n\n"
            . $textToCorrect;

        return $this->chat($agent, $prompt);
    }

    private function findBodyContent(array $context): string
    {
        $candidates = [];

        foreach ($context as $key => $value) {
            if (in_array($key, self::SYSTEM_KEYS, true)) {
                continue;
            }
            if (! is_string($value) || mb_strlen($value) < 50) {
                continue;
            }
            if ($this->isImageOutput($value)) {
                continue;
            }
            if ($this->isHashtagList($value)) {
                continue;
            }
            if ($this->isQaOutput($value)) {
                continue;
            }

            $candidates[] = $value;
        }

        return end($candidates) ?: '';
    }

    private function isImageOutput(string $content): bool
    {
        return str_contains($content, '![')
            || str_contains($content, '**SD Prompt:**')
            || str_contains($content, 'Stable Diffusion');
    }

    private function isHashtagList(string $content): bool
    {
        $words = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 3) {
            return false;
        }
        $hashtagCount = count(array_filter($words, fn ($w) => str_starts_with($w, '#')));

        return ($hashtagCount / count($words)) > 0.4;
    }

    private function isQaOutput(string $content): bool
    {
        return (bool) preg_match('/^\s*\{.*"score"\s*:/s', $content);
    }
}
