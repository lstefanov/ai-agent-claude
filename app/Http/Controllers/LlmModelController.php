<?php

namespace App\Http\Controllers;

use App\Models\LlmModel;
use App\Services\OllamaService;
use Illuminate\Http\Request;

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
        $available = collect($ollama->listModels())->pluck('name')->toArray();

        LlmModel::query()->update(['is_available' => false]);

        foreach ($available as $tag) {
            LlmModel::where('ollama_tag', $tag)->update(['is_available' => true]);
        }

        $count = count($available);
        return redirect()->route('models.index')
            ->with('success', "Синхронизирани $count модела от Ollama.");
    }

    public function pull(LlmModel $model, OllamaService $ollama)
    {
        $result = $ollama->pull($model->ollama_tag);

        if ($result) {
            $model->update(['is_available' => true]);
            return redirect()->route('models.index')
                ->with('success', "Моделът {$model->display_name} беше изтеглен успешно.");
        }

        return redirect()->route('models.index')
            ->with('error', "Грешка при изтегляне на {$model->display_name}.");
    }
}
