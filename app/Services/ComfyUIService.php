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
        $this->baseUrl = config('services.comfyui.url', 'http://localhost:8188');
        $this->checkpoint = config('services.comfyui.checkpoint', 'sd_xl_base_1.0.safetensors');
        $this->negativePrompt = config(
            'services.comfyui.negative_prompt',
            'ugly, deformed, noisy, blurry, distorted, low quality, watermark, text, signature'
        );
    }

    /**
     * Build a ComfyUI workflow by injecting the text prompt into the SDXL template.
     *
     * Празни $overrides → пълна обратна съвместимост (рандом seed, конструкторни
     * checkpoint/negative — текущото поведение на image-агентите). Org портретите
     * подават детерминистични overrides ($overrides['seed'|'checkpoint'|'negative'])
     * → стабилен портрет per член, който се сменя само при смяна на демографията (§1.1).
     *
     * @param  array{seed?: int, checkpoint?: string, negative?: string}  $overrides
     */
    public function buildWorkflow(string $positivePrompt, array $overrides = []): array
    {
        $templatePath = resource_path('comfyui/workflow_sdxl.json');

        if (! file_exists($templatePath)) {
            throw new \RuntimeException("ComfyUI workflow template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        // json_encode() produces a properly-escaped JSON string (with outer quotes).
        // Strip the outer quotes to get a bare value safe for insertion into JSON.
        $jsonStr = fn (string $s): string => substr(json_encode($s, JSON_UNESCAPED_UNICODE), 1, -1);

        // Overrides → иначе конструкторните стойности (image-агенти).
        $checkpoint = (string) ($overrides['checkpoint'] ?? '') !== '' ? $overrides['checkpoint'] : $this->checkpoint;
        $negative = (string) ($overrides['negative'] ?? '') !== '' ? $overrides['negative'] : $this->negativePrompt;

        $workflow = str_replace(
            ['__CHECKPOINT__', '__POSITIVE__', '__NEGATIVE__'],
            [
                $jsonStr($checkpoint),
                $jsonStr($positivePrompt),
                $jsonStr($negative),
            ],
            $template
        );

        $decoded = json_decode($workflow, true);

        if ($decoded === null) {
            throw new \RuntimeException('Failed to parse ComfyUI workflow JSON after substitution: '.json_last_error_msg());
        }

        // Детерминистичен seed при подаден override; иначе рандом (уникален на всяко повикване).
        $seed = isset($overrides['seed']) ? (int) $overrides['seed'] : null;
        foreach ($decoded as &$node) {
            if (isset($node['inputs']['seed'])) {
                $node['inputs']['seed'] = $seed ?? rand(1, 999_999_999);
            }
        }

        return $decoded;
    }

    /**
     * Submit a workflow to ComfyUI and return the prompt_id.
     */
    public function generate(array $workflow): string
    {
        $response = Http::timeout(30)->post($this->baseUrl.'/prompt', [
            'prompt' => $workflow,
        ]);

        $response->throw();

        $promptId = $response->json('prompt_id');

        if (! $promptId) {
            throw new \RuntimeException('ComfyUI did not return a prompt_id. Response: '.$response->body());
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
            if (! isset($nodeOutput['images'])) {
                continue;
            }

            $image = $nodeOutput['images'][0];
            $filename = $image['filename'];
            $subfolder = $image['subfolder'] ?? '';

            $imageUrl = "{$this->baseUrl}/view?filename={$filename}&subfolder={$subfolder}&type=output";
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
