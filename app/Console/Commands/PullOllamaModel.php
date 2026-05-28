<?php

namespace App\Console\Commands;

use App\Models\LlmModel;
use Illuminate\Console\Command;

class PullOllamaModel extends Command
{
    protected $signature   = 'models:pull {tag : The Ollama model tag to pull}';
    protected $description = 'Pull an Ollama model via streaming API and track progress in DB';

    public function handle(): int
    {
        $tag   = $this->argument('tag');
        $model = LlmModel::where('ollama_tag', $tag)->first();

        if (!$model) {
            $this->error("Model not found in DB: {$tag}");
            return Command::FAILURE;
        }

        $baseUrl = config('services.ollama.url', 'http://localhost:11434');

        $model->update(['pull_status' => 'pulling', 'pull_progress' => 0]);
        $this->line("Pulling {$tag}...");

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => json_encode(['name' => $tag, 'stream' => true]),
                'timeout'       => 600,
                'ignore_errors' => true,
            ],
        ]);

        $stream = @fopen($baseUrl . '/api/pull', 'r', false, $context);

        if (!$stream) {
            $model->update(['pull_status' => 'failed']);
            $this->error("Cannot connect to Ollama at {$baseUrl}");
            return Command::FAILURE;
        }

        $chunks      = [];
        $lastSaved   = -1;
        $lineCounter = 0;

        while (!feof($stream)) {
            $line = trim(fgets($stream, 8192));
            if (empty($line)) {
                continue;
            }

            $data = json_decode($line, true);
            if (!$data) {
                continue;
            }

            // Success signal
            if (($data['status'] ?? '') === 'success') {
                $model->update(['pull_status' => 'completed', 'pull_progress' => 100, 'is_available' => true]);
                // Update size_mb from actual download if we can measure
                if (!empty($chunks)) {
                    $totalBytes = array_sum(array_column($chunks, 'total'));
                    if ($totalBytes > 0) {
                        $model->update(['size_mb' => (int) round($totalBytes / 1024 / 1024)]);
                    }
                }
                $this->info("Done: {$tag}");
                fclose($stream);
                return Command::SUCCESS;
            }

            // Progress tracking per file chunk
            if (isset($data['total']) && $data['total'] > 0 && isset($data['digest'])) {
                $chunks[$data['digest']] = [
                    'total'     => $data['total'],
                    'completed' => $data['completed'] ?? 0,
                ];

                $lineCounter++;
                // Write to DB every 20 lines or when progress changes by ≥2%
                if ($lineCounter % 20 === 0) {
                    $totalBytes     = array_sum(array_column($chunks, 'total'));
                    $completedBytes = array_sum(array_column($chunks, 'completed'));
                    $progress       = $totalBytes > 0
                        ? (int) (($completedBytes / $totalBytes) * 100)
                        : 0;

                    if (abs($progress - $lastSaved) >= 2) {
                        $model->update(['pull_progress' => $progress]);
                        $lastSaved = $progress;
                        $this->line("Progress: {$progress}%");
                    }
                }
            }
        }

        fclose($stream);

        // If stream ended without 'success', mark as failed
        $model->refresh();
        if ($model->pull_status !== 'completed') {
            $model->update(['pull_status' => 'failed']);
            $this->error("Pull ended without success signal for {$tag}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
