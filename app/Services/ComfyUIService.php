<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ComfyUIService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.comfyui.url', 'http://localhost:8188');
    }

    public function generate(string $workflowJson): string
    {
        $workflow = json_decode($workflowJson, true);

        $response = Http::timeout(30)->post($this->baseUrl . '/prompt', [
            'prompt' => $workflow ?? $workflowJson,
        ]);

        $response->throw();

        return $response->json('prompt_id');
    }

    public function getResult(string $promptId, int $timeoutSeconds = 180): ?string
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $response = Http::timeout(10)->get("{$this->baseUrl}/history/{$promptId}");

            if ($response->ok()) {
                $data = $response->json($promptId);

                if ($data && isset($data['status']['completed']) && $data['status']['completed']) {
                    return $this->downloadAndSaveImage($data, $promptId);
                }
            }

            sleep(2);
        }

        return null;
    }

    private function downloadAndSaveImage(array $historyData, string $promptId): ?string
    {
        foreach ($historyData['outputs'] ?? [] as $nodeOutput) {
            if (isset($nodeOutput['images'])) {
                $image     = $nodeOutput['images'][0];
                $filename  = $image['filename'];
                $subfolder = $image['subfolder'] ?? '';

                $imageUrl  = "{$this->baseUrl}/view?filename={$filename}&subfolder={$subfolder}&type=output";
                $imageData = Http::timeout(60)->get($imageUrl)->body();

                $path = "generated/{$promptId}.png";
                Storage::disk('public')->put($path, $imageData);

                return Storage::disk('public')->url($path);
            }
        }

        return null;
    }

    public function isAvailable(): bool
    {
        try {
            Http::timeout(3)->get($this->baseUrl)->throw();
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
