<?php

namespace App\Jobs;

use App\Models\LlmModel;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOllamaModelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OllamaService $ollama): void
    {
        $available = collect($ollama->listModels())->pluck('name')->toArray();

        LlmModel::query()->update(['is_available' => false]);

        foreach ($available as $tag) {
            LlmModel::where('ollama_tag', $tag)->update(['is_available' => true]);
        }
    }
}
