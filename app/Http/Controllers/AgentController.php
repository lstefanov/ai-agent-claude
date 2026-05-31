<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Flow;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(private \App\Services\OllamaService $ollama) {}

    public function store(Request $request, Flow $flow)
    {
        $validator = validator($request->all(), [
            'name' => 'required|string|max:255',
            'model' => 'required|string',
            'type' => 'nullable|string|max:50',
            'role' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'prompt_template' => 'nullable|string',
            'is_verifier' => 'boolean',
            'qa_threshold' => 'nullable|integer|min:0|max:100',
            'config' => 'nullable|array',
            'config.temperature' => 'nullable|numeric|min:0|max:2',
            'config.top_p' => 'nullable|numeric|min:0|max:1',
            'config.top_k' => 'nullable|integer|min:1|max:200',
            'config.repeat_penalty' => 'nullable|numeric|min:0|max:2',
            'config.num_predict' => 'nullable|integer|min:-1',
            'config.qa' => 'nullable|array',
            'config.qa.enabled' => 'nullable|boolean',
            'config.qa.verifier_agent_id' => 'nullable|integer',
            'config.qa.threshold' => 'nullable|integer|min:0|max:100',
            'config.qa.max_retries' => 'nullable|integer|min:0|max:10',
            'config.qa.custom_prompt' => 'nullable|string|max:2000',
        ]);

        $validator->after(fn ($validator) => $this->validateStepQaPolicy($validator, $request, $flow));

        $validated = $validator->validate();

        $order = ($flow->agents()->max('order') ?? 0) + 1;
        $type = $validated['type'] ?? 'content_bg';
        $isVerifier = $request->boolean('is_verifier') || $type === 'qa_verifier';
        $config = $this->normalizeConfig($validated['config'] ?? null, $isVerifier);

        $agent = $flow->agents()->create([
            'name' => $validated['name'],
            'model' => $validated['model'],
            'type' => $type,
            'role' => $validated['role'] ?? '',
            'system_prompt' => $validated['system_prompt'] ?? '',
            'prompt_template' => $validated['prompt_template'] ?? '',
            'order' => $order,
            'config' => $config,
            'is_verifier' => $isVerifier,
            'qa_threshold' => $isVerifier ? ($validated['qa_threshold'] ?? 75) : null,
            'is_active' => true,
        ]);

        return response()->json(['agent' => $agent], 201);
    }

    public function edit(Flow $flow, Agent $agent)
    {
        $models = LlmModel::where('is_enabled', true)
            ->where('is_available', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for']);

        return view('agents.edit', compact('flow', 'agent', 'models'));
    }

    public function update(Request $request, Flow $flow, Agent $agent)
    {
        $validator = validator($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'role' => 'required|string',
            'system_prompt' => 'nullable|string',
            'prompt_template' => 'required|string',
            'model' => 'required|string',
            'is_active' => 'boolean',
            'is_verifier' => 'boolean',
            'qa_threshold' => 'nullable|integer|min:0|max:100',
            // Output preferences
            'output_language' => 'nullable|string|max:10',
            'output_tone' => 'nullable|string|max:30',
            'output_style' => 'nullable|string|max:30',
            'output_format' => 'nullable|string|max:30',
            'output_role' => 'nullable|in:body,appendix,hidden',
            // Model parameters (stored in config JSON)
            'config' => 'nullable|array',
            'config.temperature' => 'nullable|numeric|min:0|max:2',
            'config.top_p' => 'nullable|numeric|min:0|max:1',
            'config.top_k' => 'nullable|integer|min:1|max:200',
            'config.repeat_penalty' => 'nullable|numeric|min:0|max:2',
            'config.num_predict' => 'nullable|integer|min:-1',
            'config.qa' => 'nullable|array',
            'config.qa.enabled' => 'nullable|boolean',
            'config.qa.verifier_agent_id' => 'nullable|integer',
            'config.qa.threshold' => 'nullable|integer|min:0|max:100',
            'config.qa.max_retries' => 'nullable|integer|min:0|max:10',
            'config.qa.custom_prompt' => 'nullable|string|max:2000',
        ]);

        $validator->after(fn ($validator) => $this->validateStepQaPolicy($validator, $request, $flow, $agent));

        $validated = $validator->validate();

        // Convert empty string to null for output_role
        if (array_key_exists('output_role', $validated)) {
            $validated['output_role'] = $validated['output_role'] ?: null;
        }

        $validated['is_verifier'] = ($validated['is_verifier'] ?? false) || ($validated['type'] ?? $agent->type) === 'qa_verifier';
        $validated['qa_threshold'] = $validated['is_verifier'] ? ($validated['qa_threshold'] ?? $agent->qa_threshold ?? 75) : null;
        if (array_key_exists('config', $validated)) {
            $validated['config'] = $this->normalizeConfig($validated['config'], $validated['is_verifier'], $agent->config ?? []);
        }

        $agent->update($validated);

        if ($request->expectsJson()) {
            return response()->json(['agent' => $agent->fresh()]);
        }

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Агентът е обновен.');
    }

    public function destroy(Flow $flow, Agent $agent)
    {
        $agent->delete();

        $flow->agents()->orderBy('order')->get()
            ->each(fn ($a, $i) => $a->update(['order' => $i + 1]));

        return response()->noContent();
    }

    public function reorder(Request $request, Flow $flow)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $position => $id) {
            $flow->agents()->where('id', $id)->update(['order' => $position + 1]);
        }

        return response()->json(['ok' => true]);
    }

    public function generateAgentField(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'field'            => 'required|in:role,system_prompt,prompt_template,qa_custom_prompt',
            'agent_name'       => 'required|string|max:200',
            'agent_type'       => 'required|string|max:100',
            'flow_description' => 'nullable|string|max:1000',
            'role'             => 'nullable|string|max:2000',
            'system_prompt'    => 'nullable|string|max:5000',
            'prompt_template'  => 'nullable|string|max:5000',
        ]);

        $name        = $validated['agent_name'];
        $type        = $validated['agent_type'];
        $flowDesc    = $validated['flow_description'] ?? '';
        $role        = $validated['role'] ?? '';
        $sysPrompt   = $validated['system_prompt'] ?? '';
        $promptTmpl  = $validated['prompt_template'] ?? '';

        [$systemPrompt, $userMessage] = match ($validated['field']) {
            'role'             => $this->buildRolePrompt($name, $type, $flowDesc),
            'system_prompt'    => $this->buildSystemPromptPrompt($name, $type, $role, $flowDesc),
            'prompt_template'  => $this->buildPromptTemplatePrompt($name, $type, $role, $sysPrompt),
            'qa_custom_prompt' => $this->buildQaPrompt($name, $type, $sysPrompt, $promptTmpl),
        };

        try {
            $generated = $this->ollama->chat(
                model: config('services.ollama.generator_model', 'mistral-nemo'),
                systemPrompt: $systemPrompt,
                userMessage: $userMessage,
                options: ['temperature' => 0.3, 'num_predict' => 800]
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'AI услугата не е достъпна. Провери дали Ollama работи.'], 503);
        }

        return response()->json(['generated' => trim($generated)]);
    }

    private function buildRolePrompt(string $name, string $type, string $flowDesc): array
    {
        $context = $flowDesc ? "в автоматизация която: {$flowDesc}" : '';
        return [
            'Ти си експерт по проектиране на AI агенти за бизнес автоматизации. Генерираш кратки, конкретни описания на роли. Отговаряй САМО с текста на ролята — без въведение, без кавички, без обяснения.',
            "Напиши роля/описание (2-3 изречения на БЪЛГАРСКИ) за AI агент:\n- Име: {$name}\n- Тип: {$type}\n{$context}\n\nОпиши: какво прави агентът, какъв вход получава и какъв изход произвежда.",
        ];
    }

    private function buildSystemPromptPrompt(string $name, string $type, string $role, string $flowDesc): array
    {
        $context = $flowDesc ? "Контекст на flow-а: {$flowDesc}" : '';
        $roleCtx = $role ? "Роля на агента: {$role}" : '';
        return [
            'Ти си експерт по писане на system prompt-и за AI агенти. Създаваш детайлни, ефективни system prompt-и. Отговаряй САМО с текста на system prompt-а — без въведение, без кавички.',
            "Напиши system prompt (минимум 3 изречения на БЪЛГАРСКИ) за AI агент:\n- Име: {$name}\n- Тип: {$type}\n{$roleCtx}\n{$context}\n\nSystem prompt-ът трябва да дефинира: персоната на агента, способностите му, езика (БЪЛГАРСКИ), ограниченията и стила на отговор.",
        ];
    }

    private function buildPromptTemplatePrompt(string $name, string $type, string $role, string $sysPrompt): array
    {
        $roleCtx = $role ? "Роля: {$role}" : '';
        $sysCtx  = $sysPrompt ? "System prompt: " . mb_substr($sysPrompt, 0, 500) : '';
        return [
            'Ти си експерт по писане на prompt шаблони за AI агенти. Създаваш детайлни шаблони с конкретни инструкции. Отговаряй САМО с текста на prompt шаблона — без въведение, без кавички.',
            "Напиши prompt шаблон (минимум 5 изречения на БЪЛГАРСКИ) за AI агент:\n- Име: {$name}\n- Тип: {$type}\n{$roleCtx}\n{$sysCtx}\n\nШаблонът трябва да включва: конкретни инструкции за формат, тон, дължина, какво да се включи/изключи. Използвай placeholder-и {{company_description}}, {{input}}, {{topic}} където е подходящо.",
        ];
    }

    private function buildQaPrompt(string $name, string $type, string $sysPrompt, string $promptTmpl): array
    {
        $sysCtx  = $sysPrompt ? "System prompt: " . mb_substr($sysPrompt, 0, 400) : '';
        $tmplCtx = $promptTmpl ? "Prompt шаблон: " . mb_substr($promptTmpl, 0, 400) : '';
        return [
            'Ти си експерт по Quality Assurance за AI системи. Създаваш конкретни критерии за проверка на изхода на AI агенти. Отговаряй САМО с текста на QA критериите — без въведение, без кавички.',
            "Напиши QA критерии (2-3 изречения на БЪЛГАРСКИ) за проверка на изхода на AI агент:\n- Име: {$name}\n- Тип: {$type}\n{$sysCtx}\n{$tmplCtx}\n\nКритериите трябва да описват конкретно какво ТРЯБВА да присъства в изхода, за да се счита за качествен.",
        ];
    }

    private function validateStepQaPolicy($validator, Request $request, Flow $flow, ?Agent $agent = null): void
    {
        if (! $request->boolean('config.qa.enabled')) {
            return;
        }

        $type = $request->input('type', $agent?->type ?? 'content_bg');
        $isVerifier = $request->boolean('is_verifier') || $type === 'qa_verifier';
        if ($isVerifier) {
            $validator->errors()->add('config.qa.enabled', 'QA verifier агент не може да има post-step QA политика.');
        }

        $verifierId = $request->input('config.qa.verifier_agent_id');
        if (! $verifierId) {
            $validator->errors()->add('config.qa.verifier_agent_id', 'Избери активен QA verifier агент.');

            return;
        }

        if ($agent && (int) $verifierId === (int) $agent->id) {
            $validator->errors()->add('config.qa.verifier_agent_id', 'Агент не може да валидира сам себе си.');

            return;
        }

        $verifierExists = $flow->agents()
            ->where('id', $verifierId)
            ->where('is_active', true)
            ->where('is_verifier', true)
            ->exists();

        if (! $verifierExists) {
            $validator->errors()->add('config.qa.verifier_agent_id', 'QA политиката трябва да сочи към активен QA verifier агент от същия flow.');
        }
    }

    private function normalizeConfig(?array $config, bool $isVerifier, array $existing = []): array
    {
        $config = array_replace($existing, $config ?? []);
        if ($config === []) {
            $config = ['temperature' => 0.7, 'num_predict' => 1000];
        }

        if ($isVerifier) {
            unset($config['qa']);

            return $config;
        }

        $qa = $config['qa'] ?? [];
        $enabled = filter_var($qa['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            $config['qa'] = ['enabled' => false];

            return $config;
        }

        $config['qa'] = [
            'enabled' => true,
            'verifier_agent_id' => (int) $qa['verifier_agent_id'],
            'threshold' => (int) ($qa['threshold'] ?? 75),
            'max_retries' => (int) ($qa['max_retries'] ?? 3),
            'custom_prompt' => trim($qa['custom_prompt'] ?? ''),
        ];

        return $config;
    }
}
