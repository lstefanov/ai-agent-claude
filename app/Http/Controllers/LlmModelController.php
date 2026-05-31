<?php

namespace App\Http\Controllers;

use App\Models\LlmModel;
use App\Services\OllamaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LlmModelController extends Controller
{
    public function index()
    {
        $models = LlmModel::orderBy('category')->orderBy('display_name')->get()
            ->groupBy('category');

        return view('models.index', compact('models'));
    }

    public function sync(OllamaService $ollama)
    {
        $available = collect($ollama->listModels());

        LlmModel::query()->update(['is_available' => false]);

        foreach ($available as $item) {
            $tag = $item['name'] ?? $item['model'] ?? null;
            if (! $tag) {
                continue;
            }
            $sizeMb = isset($item['size']) ? (int) round($item['size'] / 1024 / 1024) : null;

            // Ollama appends :latest to tags stored without an explicit version.
            // Match both the full tag (e.g. phi3.5:latest) and the bare tag (phi3.5).
            $bareTag = str_ends_with($tag, ':latest')
                ? substr($tag, 0, -strlen(':latest'))
                : null;

            $query = LlmModel::where('ollama_tag', $tag);
            if ($bareTag) {
                $query->orWhere('ollama_tag', $bareTag);
            }

            $query->update([
                'is_available' => true,
                'size_mb' => $sizeMb,
                'pull_status' => 'completed',
                'pull_progress' => 100,
                'pull_error' => null,
            ]);
        }

        return redirect()->route('models.index')
            ->with('success', "Синхронизирани {$available->count()} модела от Ollama.");
    }

    public function pull(LlmModel $model)
    {
        if ($model->pull_status === 'pulling') {
            return response()->json(['started' => false, 'message' => 'Вече се изтегля']);
        }

        $model->update(['pull_status' => 'pulling', 'pull_progress' => 0]);

        // Start artisan command as a detached background process
        // PHP_CLI_BINARY in .env lets you override when web PHP ≠ CLI PHP (e.g. MAMP)
        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tag = escapeshellarg($model->ollama_tag);

        $logFile = escapeshellarg(storage_path("logs/pull-{$model->id}.log"));
        exec("{$php} {$artisan} models:pull {$tag} >> {$logFile} 2>&1 &");

        return response()->json(['started' => true]);
    }

    public function pullStatus(LlmModel $model)
    {
        $model->refresh();

        // Read last meaningful line from the pull log to show live phase
        $phase = null;
        $logFile = storage_path("logs/pull-{$model->id}.log");
        if (file_exists($logFile)) {
            $lines = array_filter(array_map('trim', file($logFile)));
            // Walk backwards to find the last stream line (starts with "< ")
            foreach (array_reverse($lines) as $line) {
                // Strip timestamp: "[HH:MM:SS] < {json}"
                if (preg_match('/\] < (.+)$/', $line, $m)) {
                    $data = json_decode($m[1], true);
                    if (isset($data['status'])) {
                        $phase = $this->translateOllamaPhase($data['status']);
                    }
                    break;
                }
            }
        }

        return response()->json([
            'status' => $model->pull_status ?? 'idle',
            'progress' => $model->pull_progress ?? 0,
            'is_available' => (bool) $model->is_available,
            'size_mb' => $model->size_mb,
            'pull_error' => $model->pull_error,
            'pull_phase' => $phase,
        ]);
    }

    private function translateOllamaPhase(string $status): string
    {
        return match (true) {
            str_contains($status, 'pulling manifest') => 'Изтегляне на манифест…',
            str_contains($status, 'pulling fs layer') => 'Изтегляне на слоеве…',
            str_contains($status, 'downloading') => 'Изтегляне…',
            str_contains($status, 'verifying') => 'Проверка на SHA256…',
            str_contains($status, 'writing manifest') => 'Запис на манифест…',
            str_contains($status, 'removing') => 'Почистване…',
            default => $status,
        };
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'ollama_tag' => 'required|string|max:100|unique:llm_models,ollama_tag',
            'display_name' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'description' => 'nullable|string',
            'ram_required_gb' => 'nullable|numeric|min:0',
            'size_mb' => 'nullable|integer|min:0',
        ]);

        LlmModel::create(array_merge($validated, [
            'is_available' => false,
            'is_enabled' => true,
        ]));

        return redirect()->route('models.index')
            ->with('success', "Моделът {$validated['display_name']} беше добавен. Можеш да го изтеглиш с ⬇ Изтегли.");
    }

    public function toggle(LlmModel $model)
    {
        $model->update(['is_enabled' => ! $model->is_enabled]);

        $state = $model->is_enabled ? 'включен' : 'изключен';

        return redirect()->route('models.index')
            ->with('success', "{$model->display_name} е {$state}.");
    }

    public function test(LlmModel $model)
    {
        $cacheKey = $this->testCacheKey($model);

        Cache::put($cacheKey, [
            'status' => 'testing',
            'success' => null,
            'response' => null,
            'error' => null,
            'elapsed_ms' => null,
        ], now()->addMinutes(10));

        if (! app()->runningUnitTests()) {
            $php = escapeshellarg(env('PHP_CLI_BINARY', PHP_BINARY));
            $artisan = escapeshellarg(base_path('artisan'));
            $modelId = escapeshellarg((string) $model->id);
            $logFile = escapeshellarg(storage_path("logs/model-test-{$model->id}.log"));

            exec("{$php} {$artisan} models:test {$modelId} >> {$logFile} 2>&1 &");
        }

        return response()->json(['status' => 'testing']);
    }

    public function testStatus(LlmModel $model)
    {
        return response()->json(Cache::get($this->testCacheKey($model), [
            'status' => 'idle',
            'success' => null,
            'response' => null,
            'error' => null,
            'elapsed_ms' => null,
        ]));
    }

    private function testCacheKey(LlmModel $model): string
    {
        return "llm_model_test_{$model->id}";
    }
}
