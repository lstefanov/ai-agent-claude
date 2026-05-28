<?php

namespace App\Console\Commands;

use App\Models\LlmModel;
use Illuminate\Console\Command;

class PullOllamaModel extends Command
{
    protected $signature   = 'models:pull {tag : The Ollama model tag to pull}';
    protected $description = 'Pull an Ollama model via streaming API and track progress in DB';

    private string $logFile;

    public function handle(): int
    {
        $tag   = $this->argument('tag');
        $model = LlmModel::where('ollama_tag', $tag)->first();

        if (!$model) {
            $this->logLine("ERROR: Model not found in DB: {$tag}");
            return Command::FAILURE;
        }

        $this->logFile = storage_path("logs/pull-{$model->id}.log");

        $this->logLine("Starting pull for: {$tag}");
        $model->update(['pull_status' => 'pulling', 'pull_progress' => 0, 'pull_error' => null]);

        $baseUrl = config('services.ollama.url', 'http://localhost:11434');
        $this->logLine("Ollama URL: {$baseUrl}");

        // ── 1. Check Ollama is reachable ──────────────────────────────────
        $pingCtx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $ping    = @file_get_contents($baseUrl . '/api/tags', false, $pingCtx);

        if ($ping === false) {
            return $this->pullFail($model, "Не може да се свърже с Ollama на {$baseUrl}. Провери дали Ollama работи.");
        }
        $this->logLine("Ollama is reachable");

        // ── 2. Open streaming pull ────────────────────────────────────────
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
            return $this->pullFail($model, "Не може да се отвори stream към Ollama /api/pull.");
        }

        // ── 3. Read HTTP status from response headers ─────────────────────
        $meta        = stream_get_meta_data($stream);
        $httpStatus  = $this->extractHttpStatus($meta['wrapper_data'] ?? []);
        $this->logLine("HTTP status: {$httpStatus}");

        if ($httpStatus >= 400) {
            $body = stream_get_contents($stream);
            fclose($stream);
            $decoded = json_decode($body, true);
            $errMsg  = $decoded['error'] ?? "HTTP {$httpStatus}: {$body}";
            return $this->pullFail($model, $errMsg);
        }

        // ── 4. Parse streaming JSON lines ─────────────────────────────────
        $chunks      = [];
        $lastSaved   = -1;
        $lineCounter = 0;

        while (!feof($stream)) {
            $line = trim(fgets($stream, 8192));
            if (empty($line)) continue;

            $this->logLine("< " . $line);

            $data = json_decode($line, true);
            if (!$data) continue;

            // Ollama error inside the stream
            if (isset($data['error']) && !empty($data['error'])) {
                fclose($stream);
                return $this->pullFail($model, $data['error']);
            }

            // Success
            if (($data['status'] ?? '') === 'success') {
                $totalBytes = array_sum(array_column($chunks, 'total'));
                if ($totalBytes > 0) {
                    $model->update(['size_mb' => (int) round($totalBytes / 1024 / 1024)]);
                }
                $model->update(['pull_status' => 'completed', 'pull_progress' => 100, 'is_available' => true, 'pull_error' => null]);
                $this->logLine("SUCCESS: {$tag}");
                fclose($stream);
                return Command::SUCCESS;
            }

            // Progress
            if (isset($data['total']) && $data['total'] > 0 && isset($data['digest'])) {
                $chunks[$data['digest']] = [
                    'total'     => $data['total'],
                    'completed' => $data['completed'] ?? 0,
                ];

                $lineCounter++;
                if ($lineCounter % 5 === 0) {
                    $totalBytes     = array_sum(array_column($chunks, 'total'));
                    $completedBytes = array_sum(array_column($chunks, 'completed'));
                    $progress       = $totalBytes > 0 ? (int)(($completedBytes / $totalBytes) * 100) : 0;

                    if (abs($progress - $lastSaved) >= 1) {
                        $model->update(['pull_progress' => $progress]);
                        $lastSaved = $progress;
                        $this->logLine("Progress: {$progress}%");
                    }
                }
            }
        }

        fclose($stream);

        // Stream ended without 'success' — check final state
        $model->refresh();
        if ($model->pull_status !== 'completed') {
            return $this->pullFail($model, "Stream приключи без потвърждение за успех. Може да е прекъсната връзката или модела не съществува в Ollama registry.");
        }

        return Command::SUCCESS;
    }

    private function pullFail(LlmModel $model, string $message): int
    {
        $model->update(['pull_status' => 'failed', 'pull_error' => $message]);
        $this->logLine("FAILED: {$message}");
        $this->error($message);
        return Command::FAILURE;
    }

    private function logLine(string $line): void
    {
        $entry = '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    private function extractHttpStatus(array $wrapperData): int
    {
        foreach ($wrapperData as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 200; // assume OK if header not found
    }
}
