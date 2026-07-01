<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Flow;
use App\Services\GeneratorService;
use App\Services\Org\Billing\BillableOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * AI-assist endpoint for agent-template forms: generates a single field
 * (role / system_prompt / prompt_template / qa_custom_prompt) on demand.
 *
 * Agent pipelines themselves are planned by FlowPlannerService and edited as
 * graph nodes in the builder — there is no per-agent CRUD anymore.
 *
 * Два пътя:
 *  - Scoped route `companies/{company}/agent-templates/generate-field`:
 *    фирмата идва от route binding (не от тялото) — за company template формата.
 *  - Global route `/ai/generate-agent-field` (builder):
 *    фирмата се резолвира server-side от валидиран flow_id → flow->company_id.
 *    Никога не четем company_id директно от тялото на заявката.
 */
class AgentController extends Controller
{
    public function __construct(
        private GeneratorService $llm,
        private BillableOperationService $billable,
    ) {}

    /**
     * @param  Company|null  $company  Route binding при scoped route; null при global route.
     */
    public function generateAgentField(Request $request, ?Company $company = null): JsonResponse
    {
        $validated = $request->validate([
            'field' => 'required|in:role,system_prompt,prompt_template,qa_custom_prompt',
            'agent_name' => 'required|string|max:200',
            'agent_type' => 'required|string|max:100',
            'flow_description' => 'nullable|string|max:1000',
            'role' => 'nullable|string|max:2000',
            'system_prompt' => 'nullable|string|max:5000',
            'prompt_template' => 'nullable|string|max:5000',
            // flow_id се ползва server-side за атрибуция (builder path); НЕ company_id.
            'flow_id' => 'nullable|exists:flows,id',
        ]);

        // Server-side резолюция на фирмата — scoped route взима предимство.
        $companyId = $company?->id
            ?? (isset($validated['flow_id'])
                ? Flow::find($validated['flow_id'])?->company_id
                : null);

        $name = $validated['agent_name'];
        $type = $validated['agent_type'];
        $flowDesc = $validated['flow_description'] ?? '';
        $role = $validated['role'] ?? '';
        $sysPrompt = $validated['system_prompt'] ?? '';
        $promptTmpl = $validated['prompt_template'] ?? '';

        [$systemPrompt, $userMessage] = match ($validated['field']) {
            'role' => $this->buildRolePrompt($name, $type, $flowDesc),
            'system_prompt' => $this->buildSystemPromptPrompt($name, $type, $role, $flowDesc),
            'prompt_template' => $this->buildPromptTemplatePrompt($name, $type, $role, $sysPrompt),
            'qa_custom_prompt' => $this->buildQaPrompt($name, $type, $sysPrompt, $promptTmpl),
        };

        $doAssist = fn () => $this->llm->assist(
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.3, 'num_predict' => 800]
        );

        try {
            // При наличен companyId — таксуваме кредити (best-effort); иначе admin/system path.
            $generated = $companyId !== null
                ? $this->billable->run(
                    companyId: $companyId,
                    contextType: 'text_assist',
                    subject: null,
                    work: $doAssist,
                    opKey: (string) Str::uuid(),
                )
                : $doAssist();
        } catch (\Exception $e) {
            return response()->json(['error' => 'AI услугата не е достъпна. Провери ASSIST_PROVIDER и API ключа.'], 503);
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
        $sysCtx = $sysPrompt ? 'System prompt: '.mb_substr($sysPrompt, 0, 500) : '';

        return [
            'Ти си експерт по писане на prompt шаблони за AI агенти. Създаваш детайлни шаблони с конкретни инструкции. Отговаряй САМО с текста на prompt шаблона — без въведение, без кавички.',
            "Напиши prompt шаблон (минимум 5 изречения на БЪЛГАРСКИ) за AI агент:\n- Име: {$name}\n- Тип: {$type}\n{$roleCtx}\n{$sysCtx}\n\nШаблонът трябва да включва: конкретни инструкции за формат, тон, дължина, какво да се включи/изключи. Използвай placeholder-и {{input}}, {{topic}} и {{url}} където е подходящо.",
        ];
    }

    private function buildQaPrompt(string $name, string $type, string $sysPrompt, string $promptTmpl): array
    {
        $sysCtx = $sysPrompt ? 'System prompt: '.mb_substr($sysPrompt, 0, 400) : '';
        $tmplCtx = $promptTmpl ? 'Prompt шаблон: '.mb_substr($promptTmpl, 0, 400) : '';

        return [
            'Ти си експерт по Quality Assurance за AI системи. Създаваш конкретни критерии за проверка на изхода на AI агенти. Отговаряй САМО с текста на QA критериите — без въведение, без кавички.',
            "Напиши QA критерии (2-3 изречения на БЪЛГАРСКИ) за проверка на изхода на AI агент:\n- Име: {$name}\n- Тип: {$type}\n{$sysCtx}\n{$tmplCtx}\n\nКритериите трябва да описват конкретно какво ТРЯБВА да присъства в изхода, за да се счита за качествен.",
        ];
    }
}
