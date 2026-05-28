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
        $response = Http::timeout(180)->post($this->baseUrl . '/api/chat', [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
            'stream'  => false,
            'options' => array_merge(['temperature' => 0.7], $options),
        ]);

        $response->throw();

        return $response->json('message.content', '');
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
