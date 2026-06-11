<?php

namespace App\Agents\Tools;

use App\Services\MistralOcrService;

class DocumentOcrTool implements AgentTool
{
    public function __construct(private MistralOcrService $service) {}

    public function name(): string
    {
        return 'extract_document';
    }

    public function description(): string
    {
        return 'Извлича текст и таблици от PDF/сканиран документ/изображение по URL (Mistral OCR) — ценоразписи, каталози, фактури.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL на документа (PDF/изображение).'],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * @param  array{url?: string}  $params
     */
    public function execute(array $params): string
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        return $this->service->extract($url) ?? '';
    }
}
