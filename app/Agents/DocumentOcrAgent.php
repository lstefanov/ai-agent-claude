<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Support\UrlExtractor;

class DocumentOcrAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        if (! filled(config('services.mistral.api_key'))) {
            return 'Mistral OCR не е конфигуриран. Добави MISTRAL_API_KEY в .env и рестартирай конфигурацията.';
        }

        $targets = $this->targets($agent, $agentRun, $context);
        if ($targets === []) {
            return 'Няма намерен PDF/документ/изображение URL за OCR. Подай публичен URL към PDF, сканиран документ или изображение.';
        }

        $blocks = [];
        foreach ($targets as $url) {
            $result = $this->useTool('extract_document', ['url' => $url]);
            if ($result !== null && trim($result) !== '') {
                $blocks[] = $result;
            }
        }

        if ($blocks === []) {
            return 'Mistral OCR не успя да извлече съдържание от подадените URL-и. Провери дали документите са публично достъпни.';
        }

        $extraContext = "\n\n--- OCR EXTRACTED DOCUMENT CONTENT (основен източник; не измисляй липсващи редове, цени или таблици) ---\n"
            .implode("\n\n", $blocks);

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    /**
     * @return array<int, string>
     */
    private function targets(Agent $agent, AgentRun $agentRun, array $context): array
    {
        $params = (array) ($agent->config['tool_params'] ?? []);
        $targets = [];

        $explicit = $this->renderTemplate((string) ($params['document_url'] ?? ''), $context);
        if ($explicit !== '' && ! str_contains($explicit, '{{')) {
            $targets[] = $explicit;
        }

        foreach ([$context['target_url'] ?? null, $context['url'] ?? null, $agent->prompt_template ?? '', $agentRun->input ?? ''] as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            foreach (UrlExtractor::all($source) as $found) {
                if ($this->isDocumentUrl($found)) {
                    $targets[] = $found;
                }
            }
        }

        $max = max(1, min(10, (int) ($params['max_documents'] ?? 5)));

        return array_slice(array_values(array_unique($targets)), 0, $max);
    }

    private function renderTemplate(string $template, array $context): string
    {
        $template = trim($template);
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $template = str_replace('{{'.$key.'}}', $value, $template);
            }
        }

        return trim($template);
    }

    private function isDocumentUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        return (bool) preg_match('/\.(pdf|png|jpe?g|webp|gif|tiff?|bmp)$/i', $path);
    }
}
