<?php

namespace App\Http\Controllers;

use App\Models\LlmModel;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Artisan;

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
            if (!$tag) {
                continue;
            }
            $sizeMb = isset($item['size']) ? (int) round($item['size'] / 1024 / 1024) : null;

            LlmModel::where('ollama_tag', $tag)->update(array_filter([
                'is_available' => true,
                'size_mb'      => $sizeMb,
                'pull_status'  => 'completed',
                'pull_progress' => 100,
            ], fn($v) => $v !== null));
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
        $php     = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tag     = escapeshellarg($model->ollama_tag);

        exec("{$php} {$artisan} models:pull {$tag} > /dev/null 2>&1 &");

        return response()->json(['started' => true]);
    }

    public function pullStatus(LlmModel $model)
    {
        $model->refresh();
        return response()->json([
            'status'       => $model->pull_status ?? 'idle',
            'progress'     => $model->pull_progress ?? 0,
            'is_available' => (bool) $model->is_available,
            'size_mb'      => $model->size_mb,
        ]);
    }

    public function store(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ollama_tag'     => 'required|string|max:100|unique:llm_models,ollama_tag',
            'display_name'   => 'required|string|max:255',
            'category'       => 'required|string|max:50',
            'description'    => 'nullable|string',
            'ram_required_gb'=> 'nullable|numeric|min:0',
            'size_mb'        => 'nullable|integer|min:0',
        ]);

        LlmModel::create(array_merge($validated, [
            'is_available' => false,
            'is_enabled'   => true,
        ]));

        return redirect()->route('models.index')
            ->with('success', "Моделът {$validated['display_name']} беше добавен. Можеш да го изтеглиш с ⬇ Изтегли.");
    }

    public function toggle(LlmModel $model)
    {
        $model->update(['is_enabled' => !$model->is_enabled]);

        $state = $model->is_enabled ? 'включен' : 'изключен';
        return redirect()->route('models.index')
            ->with('success', "{$model->display_name} е {$state}.");
    }

    public function test(LlmModel $model, OllamaService $ollama)
    {
        try {
            $response = $ollama->chat(
                model: $model->ollama_tag,
                systemPrompt: 'You are a test assistant. Answer in one very short sentence.',
                userMessage: 'Reply with exactly: "OK – ' . $model->display_name . ' works."',
                options: ['temperature' => 0]
            );
            return response()->json(['success' => true, 'response' => trim($response)]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'response' => $e->getMessage()]);
        }
    }
}
