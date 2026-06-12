<?php

namespace App\Services;

use App\Support\LlmUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MistralOcrService
{
    private const ENDPOINT = 'https://api.mistral.ai/v1/ocr';

    /**
     * Extract document text as markdown. Supports public PDF/document URLs and
     * image URLs accepted by Mistral OCR.
     */
    public function extract(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $this->request($this->documentPayload($url), $url);
    }

    /**
     * Extract a LOCAL file (knowledge-base upload) by sending it as a base64
     * data: URI — Mistral OCR accepts those in the same document field.
     */
    public function extractFile(string $absolutePath, string $mime): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $maxBytes = (int) config('services.mistral.ocr_max_file_mb', 25) * 1024 * 1024;
        if (filesize($absolutePath) > $maxBytes) {
            Log::warning("[MistralOCR] File too large for OCR: {$absolutePath}");

            return null;
        }

        $dataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolutePath));

        $payload = str_starts_with($mime, 'image/')
            ? ['type' => 'image_url', 'image_url' => $dataUri]
            : ['type' => 'document_url', 'document_url' => $dataUri];

        return $this->request($payload, basename($absolutePath));
    }

    /**
     * @param  array<string, string>  $documentPayload
     */
    private function request(array $documentPayload, string $label): ?string
    {
        $apiKey = config('services.mistral.api_key');

        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::withToken((string) $apiKey)
                ->acceptJson()
                ->timeout((int) config('services.mistral.ocr_timeout', 120))
                ->post((string) config('services.mistral.ocr_url', self::ENDPOINT), [
                    'model' => config('services.mistral.ocr_model', 'mistral-ocr-latest'),
                    'document' => $documentPayload,
                ]);

            if (! $response->successful()) {
                Log::warning("[MistralOCR] HTTP {$response->status()} for {$label}");

                return null;
            }

            $json = $response->json();
            if (! is_array($json)) {
                return null;
            }

            $pages = $this->pageCount($json);
            if ($pages > 0) {
                LlmUsage::addFlatCost($pages * (float) config('services.mistral.ocr_page_cost_usd', 0.002));
            }

            return $this->markdownFromResponse($json, $label);
        } catch (\Throwable $e) {
            Log::warning('[MistralOCR] '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function documentPayload(string $url): array
    {
        if ($this->isImageUrl($url)) {
            return [
                'type' => 'image_url',
                'image_url' => $url,
            ];
        }

        return [
            'type' => 'document_url',
            'document_url' => $url,
        ];
    }

    private function isImageUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        return (bool) preg_match('/\.(png|jpe?g|webp|gif|tiff?|bmp)$/i', $path);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function pageCount(array $json): int
    {
        $usagePages = data_get($json, 'usage_info.pages_processed')
            ?? data_get($json, 'usage.pages_processed')
            ?? data_get($json, 'usage.pages');

        if (is_numeric($usagePages)) {
            return max(1, (int) $usagePages);
        }

        $pages = $json['pages'] ?? [];

        return is_array($pages) ? max(1, count($pages)) : 1;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function markdownFromResponse(array $json, string $url): ?string
    {
        $pages = $json['pages'] ?? [];
        if (is_array($pages) && $pages !== []) {
            $blocks = [];
            foreach ($pages as $i => $page) {
                if (! is_array($page)) {
                    continue;
                }

                $markdown = trim((string) ($page['markdown'] ?? $page['text'] ?? ''));
                if ($markdown === '') {
                    continue;
                }

                $number = (int) ($page['index'] ?? $i + 1);
                $blocks[] = "=== OCR PAGE {$number}: {$url} ===\n{$markdown}";
            }

            return $blocks !== [] ? implode("\n\n", $blocks) : null;
        }

        $markdown = trim((string) ($json['markdown'] ?? $json['text'] ?? ''));

        return $markdown !== '' ? "=== OCR DOCUMENT: {$url} ===\n{$markdown}" : null;
    }
}
