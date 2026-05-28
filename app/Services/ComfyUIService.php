<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ComfyUIService
{
    private string $baseUrl;
    private string $checkpoint;
    private string $negativePrompt;

    public function __construct()
    {
        $this->baseUrl       = config('services.comfyui.url',      'http://localhost:8188');
        $this->checkpoint    = config('services.comfyui.checkpoint', 'sd_xl_base_1.0.safetensors');
        $this->negativePrompt = config(
            'services.comfyui.negative_prompt',
            'ugly, deformed, noisy, blurry, distorted, low quality, watermark, text, signature'
        );
    }

    /**
     * Build a ComfyUI workflow by injecting the text prompt into the SDXL template.
     * Randomises the seed on every call.
     */
    public function buildWorkflow(string $positivePrompt): array
    {
        $templatePath = resource_path('comfyui/workflow_sdxl.json');
        $template     = file_get_contents($templatePath);

        $workflow = str_replace(
            ['__CHECKPOINT__', '__POSITIVE__', '__NEGATIVE__'],
            [
                $this->checkpoint,
                addslashes($positivePrompt),
                addslashes($this->negativePrompt),
            ],
            $template
        );

        $workflow = json_decode($workflow, true);

        // Randomise seed so every run is unique
        if (isset($workflow['3']['inputs']['seed'])) {
            $workflow['3']['inputs']['seed'] = rand(1, 999999999);
        }

        return $workflow;
    }

    /**
     * Submit a workflow to ComfyUI and return the prompt_id.
     */
    public function generate(array $workflow): string
    {
        $response = Http::timeout(30)->post($this->baseUrl . '/prompt', [
            'prompt' => $workflow,
        ]);

        $response->throw();

        $promptId = $response->json('prompt_id');

        if (!$promptId) {
            throw new \RuntimeException('ComfyUI did not return a prompt_id. Response: ' . $response->body());
        }

        return $promptId;
    }

    /**
     * Poll ComfyUI until the image is ready, then download and save it.
     * Returns the public URL or null on timeout.
     */
    public function getResult(string $promptId, int $timeoutSeconds = 180): ?string
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $response = Http::timeout(10)->get("{$this->baseUrl}/history/{$promptId}");

            if ($response->ok()) {
                $data = $response->json($promptId);

                if ($data && ($data['status']['completed'] ?? false)) {
                    return $this->downloadAndSaveImage($data, $promptId);
                }
            }

            sleep(2);
        }

        Log::warning("[ComfyUI] Timeout waiting for prompt {$promptId}");
        return null;
    }

    private function downloadAndSaveImage(array $historyData, string $promptId): ?string
    {
        foreach ($historyData['outputs'] ?? [] as $nodeOutput) {
            if (!isset($nodeOutput['images'])) continue;

            $image     = $nodeOutput['images'][0];
            $filename  = $image['filename'];
            $subfolder = $image['subfolder'] ?? '';

            $imageUrl  = "{$this->baseUrl}/view?filename={$filename}&subfolder={$subfolder}&type=output";
            $imageData = Http::timeout(120)->get($imageUrl)->body();

            $path = "generated/{$promptId}.png";
            Storage::disk('public')->put($path, $imageData);

            Log::info("[ComfyUI] Image saved: storage/app/public/{$path}");

            return Storage::disk('public')->url($path);
        }

        return null;
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get($this->baseUrl)->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * List checkpoints available in ComfyUI (for validation/UI).
     */
    public function listCheckpoints(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/object_info/CheckpointLoaderSimple");
            return $response->json('CheckpointLoaderSimple.input.required.ckpt_name.0') ?? [];
        } catch (\Exception) {
            return [];
        }
    }
}
