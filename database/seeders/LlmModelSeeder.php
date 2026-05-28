<?php

namespace Database\Seeders;

use App\Models\LlmModel;
use Illuminate\Database\Seeder;

class LlmModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // Bulgarian / General Text
            ['ollama_tag' => 'todorov/bggpt',    'display_name' => 'BgGPT',           'category' => 'bulgarian',    'description' => 'Единственият модел специализиран за български език',                         'strengths' => ['bulgarian_text', 'natural_bg_language'],              'ram_required_gb' => 5.0,  'size_mb' => 2600,  'is_default_for' => ['content_bg']],
            ['ollama_tag' => 'llama3.1:8b',      'display_name' => 'LLaMA 3.1 8B',   'category' => 'general',      'description' => 'Бърз, добър баланс качество/скорост, отличен за English',                   'strengths' => ['english_text', 'balanced', 'fast'],                   'ram_required_gb' => 5.0,  'size_mb' => 4700,  'is_default_for' => ['content_en', 'summarizer']],
            ['ollama_tag' => 'llama3.1:70b',     'display_name' => 'LLaMA 3.1 70B',  'category' => 'general',      'description' => 'Много висококачествен текст, нужен силен GPU',                              'strengths' => ['high_quality_text', 'powerful'],                      'ram_required_gb' => 40.0, 'size_mb' => 39000, 'is_default_for' => []],
            ['ollama_tag' => 'llama3.2:3b',      'display_name' => 'LLaMA 3.2 3B',   'category' => 'general',      'description' => 'Ултра бърз, за прости задачи',                                             'strengths' => ['ultra_fast', 'simple_tasks'],                         'ram_required_gb' => 2.0,  'size_mb' => 2000,  'is_default_for' => []],
            ['ollama_tag' => 'gemma2:9b',        'display_name' => 'Gemma 2 9B',      'category' => 'general',      'description' => 'Google модел, отличен за structured output и анализи',                     'strengths' => ['structured_output', 'analysis'],                      'ram_required_gb' => 6.0,  'size_mb' => 5400,  'is_default_for' => []],
            ['ollama_tag' => 'gemma2:27b',       'display_name' => 'Gemma 2 27B',     'category' => 'general',      'description' => 'По-мощна версия на Gemma 2',                                               'strengths' => ['structured_output', 'powerful'],                      'ram_required_gb' => 16.0, 'size_mb' => 15800, 'is_default_for' => []],

            // JSON / Instructions
            ['ollama_tag' => 'mistral',          'display_name' => 'Mistral 7B',      'category' => 'json',         'description' => 'Отличен JSON output, следва инструкции прецизно',                          'strengths' => ['json_output', 'instruction_following', 'structured'], 'ram_required_gb' => 4.0,  'size_mb' => 4100,  'is_default_for' => ['image_prompt', 'orchestrator']],
            ['ollama_tag' => 'mistral-nemo',     'display_name' => 'Mistral NeMo 12B','category' => 'json',         'description' => 'По-мощен Mistral, добър за агентни задачи',                                'strengths' => ['json_output', 'agent_tasks'],                         'ram_required_gb' => 7.0,  'size_mb' => 7100,  'is_default_for' => []],
            ['ollama_tag' => 'mixtral:8x7b',     'display_name' => 'Mixtral 8x7B',    'category' => 'json',         'description' => 'MoE архитектура, много висок качество',                                    'strengths' => ['high_quality', 'moe_architecture'],                   'ram_required_gb' => 26.0, 'size_mb' => 26100, 'is_default_for' => []],
            ['ollama_tag' => 'qwen2.5:7b',       'display_name' => 'Qwen2.5 7B',      'category' => 'json',         'description' => 'Alibaba, отличен за structured tasks и многоезичност',                     'strengths' => ['structured_tasks', 'multilingual'],                   'ram_required_gb' => 5.0,  'size_mb' => 4700,  'is_default_for' => ['researcher']],
            ['ollama_tag' => 'qwen2.5:14b',      'display_name' => 'Qwen2.5 14B',     'category' => 'json',         'description' => 'По-мощна версия на Qwen2.5',                                               'strengths' => ['structured_tasks', 'powerful'],                       'ram_required_gb' => 9.0,  'size_mb' => 9000,  'is_default_for' => []],

            // Reasoning / Analysis
            ['ollama_tag' => 'deepseek-r1:8b',   'display_name' => 'DeepSeek-R1 8B',  'category' => 'reasoning',    'description' => 'Chain-of-thought reasoning, отличен за анализи',                           'strengths' => ['reasoning', 'analysis', 'chain_of_thought'],          'ram_required_gb' => 5.0,  'size_mb' => 4900,  'is_default_for' => ['analyzer', 'decision']],
            ['ollama_tag' => 'deepseek-r1:32b',  'display_name' => 'DeepSeek-R1 32B', 'category' => 'reasoning',    'description' => 'По-мощен reasoning модел',                                                 'strengths' => ['reasoning', 'powerful'],                              'ram_required_gb' => 20.0, 'size_mb' => 19400, 'is_default_for' => []],
            ['ollama_tag' => 'qwq:32b',          'display_name' => 'QwQ 32B',          'category' => 'reasoning',    'description' => 'Alibaba reasoning модел, отличен за сложни решения',                      'strengths' => ['reasoning', 'complex_decisions'],                     'ram_required_gb' => 20.0, 'size_mb' => 19400, 'is_default_for' => []],
            ['ollama_tag' => 'phi4',             'display_name' => 'Phi-4 14B',        'category' => 'reasoning',    'description' => 'Microsoft, много добър reasoning при малък размер',                       'strengths' => ['reasoning', 'efficient'],                             'ram_required_gb' => 9.0,  'size_mb' => 8500,  'is_default_for' => []],

            // QA / Verification
            ['ollama_tag' => 'phi3:mini',        'display_name' => 'Phi-3 Mini',       'category' => 'qa',           'description' => 'Ултра бърз, добър за QA и кратки проверки',                               'strengths' => ['fast', 'qa', 'simple_tasks'],                         'ram_required_gb' => 2.0,  'size_mb' => 2300,  'is_default_for' => ['publisher']],
            ['ollama_tag' => 'phi3.5',           'display_name' => 'Phi-3.5 Mini 3.8B', 'category' => 'qa',         'description' => 'Подобрена версия на Phi-3 Mini (правилен таг: phi3.5)',                       'strengths' => ['fast', 'qa', 'improved'],                             'ram_required_gb' => 2.5,  'size_mb' => 2200,  'is_default_for' => ['qa_verifier']],
            ['ollama_tag' => 'gemma2:2b',        'display_name' => 'Gemma 2 2B',       'category' => 'qa',           'description' => 'Google, много бърз за прости задачи',                                     'strengths' => ['fast', 'simple_tasks'],                               'ram_required_gb' => 2.0,  'size_mb' => 1600,  'is_default_for' => []],
            ['ollama_tag' => 'llama3.2:1b',      'display_name' => 'LLaMA 3.2 1B',    'category' => 'qa',           'description' => 'Най-леката опция, само за прости QA',                                     'strengths' => ['lightest', 'simple_qa'],                              'ram_required_gb' => 1.0,  'size_mb' => 1300,  'is_default_for' => []],

            // Code
            ['ollama_tag' => 'deepseek-coder-v2','display_name' => 'DeepSeek Coder V2','category' => 'code',        'description' => 'Топ модел за код генериране',                                              'strengths' => ['code_generation', 'top_coder'],                       'ram_required_gb' => 9.0,  'size_mb' => 8900,  'is_default_for' => ['code']],
            ['ollama_tag' => 'qwen2.5-coder:7b', 'display_name' => 'Qwen2.5 Coder 7B','category' => 'code',        'description' => 'Специализиран за код, бърз',                                               'strengths' => ['code_generation', 'fast'],                            'ram_required_gb' => 5.0,  'size_mb' => 4700,  'is_default_for' => []],
            ['ollama_tag' => 'codellama:13b',    'display_name' => 'CodeLlama 13B',    'category' => 'code',        'description' => 'Meta, добър за code completion',                                           'strengths' => ['code_completion'],                                    'ram_required_gb' => 8.0,  'size_mb' => 7400,  'is_default_for' => []],
            ['ollama_tag' => 'starcoder2:7b',    'display_name' => 'StarCoder2 7B',    'category' => 'code',        'description' => 'Hugging Face, добър за code tasks',                                       'strengths' => ['code_tasks'],                                         'ram_required_gb' => 4.0,  'size_mb' => 4000,  'is_default_for' => []],

            // Multilingual
            ['ollama_tag' => 'qwen2:7b',         'display_name' => 'Qwen2 7B',         'category' => 'multilingual', 'description' => '29 езика, отличен multilingual',                                           'strengths' => ['multilingual', '29_languages'],                       'ram_required_gb' => 5.0,  'size_mb' => 4400,  'is_default_for' => ['translator', 'content_bg']],
            ['ollama_tag' => 'aya:8b',           'display_name' => 'Aya 8B',           'category' => 'multilingual', 'description' => 'Cohere, специализиран за 23 езика',                                        'strengths' => ['multilingual', '23_languages'],                       'ram_required_gb' => 5.0,  'size_mb' => 4700,  'is_default_for' => []],
            ['ollama_tag' => 'aya-expanse:8b',   'display_name' => 'Aya Expanse 8B',   'category' => 'multilingual', 'description' => 'По-нова версия на Aya',                                                    'strengths' => ['multilingual', 'improved_aya'],                       'ram_required_gb' => 5.0,  'size_mb' => 4700,  'is_default_for' => []],

            // Vision
            ['ollama_tag' => 'llava:7b',             'display_name' => 'LLaVA 7B',             'category' => 'vision', 'description' => 'Анализ на изображения + текст',         'strengths' => ['image_analysis', 'vision'],        'ram_required_gb' => 5.0,  'size_mb' => 4700,  'is_default_for' => []],
            ['ollama_tag' => 'llava:13b',            'display_name' => 'LLaVA 13B',            'category' => 'vision', 'description' => 'По-добър vision модел',                  'strengths' => ['image_analysis', 'better_vision'], 'ram_required_gb' => 8.0,  'size_mb' => 7400,  'is_default_for' => []],
            ['ollama_tag' => 'moondream',            'display_name' => 'Moondream 2',           'category' => 'vision', 'description' => 'Ултра лек vision модел',                'strengths' => ['ultra_light', 'vision'],            'ram_required_gb' => 2.0,  'size_mb' => 1700,  'is_default_for' => []],
            ['ollama_tag' => 'llama3.2-vision:11b', 'display_name' => 'LLaMA 3.2 Vision 11B', 'category' => 'vision', 'description' => 'Meta vision, много добър',               'strengths' => ['vision', 'meta', 'high_quality'],  'ram_required_gb' => 8.0,  'size_mb' => 7900,  'is_default_for' => ['vision']],
        ];

        foreach ($models as $model) {
            LlmModel::updateOrCreate(
                ['ollama_tag' => $model['ollama_tag']],
                $model
            );
        }
    }
}
