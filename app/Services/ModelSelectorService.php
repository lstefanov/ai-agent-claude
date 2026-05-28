<?php

namespace App\Services;

use App\Models\LlmModel;

class ModelSelectorService
{
    private array $defaults = [
        'content_bg'   => ['primary' => 'todorov/bggpt',       'fallback' => 'qwen2:7b'],
        'content_en'   => ['primary' => 'llama3.1:8b',         'fallback' => 'mistral'],
        'image_prompt' => ['primary' => 'mistral',              'fallback' => 'qwen2.5:7b'],
        'qa_verifier'  => ['primary' => 'phi3.5:mini',          'fallback' => 'gemma2:2b'],
        'analyzer'     => ['primary' => 'deepseek-r1:8b',       'fallback' => 'phi4'],
        'researcher'   => ['primary' => 'qwen2.5:7b',           'fallback' => 'mistral-nemo'],
        'summarizer'   => ['primary' => 'llama3.1:8b',          'fallback' => 'gemma2:9b'],
        'decision'     => ['primary' => 'deepseek-r1:8b',       'fallback' => 'qwq:32b'],
        'publisher'    => ['primary' => 'phi3:mini',            'fallback' => 'llama3.2:1b'],
        'translator'   => ['primary' => 'qwen2:7b',             'fallback' => 'aya:8b'],
        'code'         => ['primary' => 'deepseek-coder-v2',    'fallback' => 'qwen2.5-coder:7b'],
        'vision'       => ['primary' => 'llama3.2-vision:11b',  'fallback' => 'llava:7b'],
        'orchestrator' => ['primary' => 'mistral',              'fallback' => 'qwen2.5:7b'],
    ];

    public function selectModel(string $agentType): string
    {
        $preferred = $this->defaults[$agentType]['primary']
            ?? config('services.ollama.fallback_model', 'llama3.1:8b');

        $available = LlmModel::where('ollama_tag', $preferred)
            ->where('is_available', true)
            ->exists();

        return $available ? $preferred : $this->getFallback($agentType);
    }

    public function getFallback(string $agentType): string
    {
        return $this->defaults[$agentType]['fallback']
            ?? config('services.ollama.fallback_model', 'llama3.1:8b');
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }
}
