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
