<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.url', 'http://localhost:11434');
    }

    public function chat(string $model, string $systemPrompt, string $userMessage, array $options = []): string
    {
        // Use stream:true so Ollama sends tokens immediately (NDJSON).
        // Without streaming, Ollama buffers the entire response before sending ANY bytes,
        // causing "0 bytes received" timeouts on long synthesis responses.
        $response = Http::withOptions(['stream' => true])  // Guzzle: don't buffer the body
            ->timeout(600)                                  // safety net — matches job timeout
            ->post($this->baseUrl . '/api/chat', [
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMessage],
                ],
                'stream'  => true,   // Ollama API: emit NDJSON chunks as tokens are generated
                'options' => array_merge(['temperature' => 0.7], $options),
            ]);

        $response->throw();

        // Accumulate NDJSON chunks into a single string
        $content = '';
        $buffer  = '';
        $body    = $response->getBody();

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $chunk = json_decode($line, true);
                if (is_array($chunk) && isset($chunk['message']['content'])) {
                    $content .= $chunk['message']['content'];
                }
            }
        }

        // Flush any remainder left in the buffer after EOF
        if ($line = trim($buffer)) {
            $chunk = json_decode($line, true);
            if (is_array($chunk) && isset($chunk['message']['content'])) {
                $content .= $chunk['message']['content'];
            }
        }

        return $content;
    }

    public function listModels(): array
    {
        $response = Http::timeout(10)->get($this->baseUrl . '/api/tags');

        if ($response->failed()) {
            return [];
        }

        return $response->json('models', []);
    }

    public function pull(string $tag): bool
    {
        try {
            // stream:false waits for the full pull to complete (can take minutes for large models)
            $response = Http::timeout(600)->post($this->baseUrl . '/api/pull', [
                'name'   => $tag,
                'stream' => false,
            ]);
            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    public function isAvailable(): bool
    {
        try {
            Http::timeout(3)->get($this->baseUrl . '/api/tags')->throw();
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
