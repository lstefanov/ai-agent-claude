<?php

namespace App\Console\Commands;

use App\Models\AgentGenerationLog;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CreditReservation;
use App\Models\Flow;
use App\Models\FlowDraft;
use App\Services\AgentGeneratorService;
use App\Services\FlowVersionService;
use App\Services\GeneratorService;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\PersonaService;
use App\Services\Org\TaskProposalBriefService;
use App\Services\Org\TaskRunService;
use App\Services\PlanLibraryService;
use App\Support\LlmContext;
use App\Support\ModelLevel;
use App\Support\PlannerPhases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class GenerateAgentsCommand extends Command
{
    protected $signature = 'flows:generate-agents {token : Cache token for this generation request}';

    protected $description = 'Run agent generation in the background and store result in cache';

    public function handle(AgentGeneratorService $generator, FlowVersionService $versions): int
    {
        $token = $this->argument('token');
        $cacheKey = "agent_gen_{$token}";

        // Read request data from cache
        $request = Cache::get("agent_gen_request_{$token}");
        if (! $request) {
            Log::error("[GenerateAgents] Token not found in cache: {$token}");

            return Command::FAILURE;
        }

        Log::info("[GenerateAgents] Starting for token {$token}, company {$request['company_id']}");

        // Org задача (§0.5.6): резервацията за generation е създадена от TaskRunService
        // по детерминистичен субект — намираме я тук за атрибуция + settle/refund.
        $assistantTaskId = $request['assistant_task_id'] ?? null;
        $genReservation = $assistantTaskId
            ? $this->generationReservation((int) $assistantTaskId)
            : null;

        // Org (§0.5.5 разширен): персоната на асистента-собственик оформя и
        // ГЕНЕРАЦИЯТА — планерът пише агентите/промптовете в неговия стил. Същият
        // блок като runtime injection (PersonaService) → консистентност. Не-org
        // (без задача/персона) → null → промптът на планера е непроменен.
        $personaBlock = null;
        $personaPolicy = null;
        if ($assistantTaskId && ($owner = AssistantTask::with('orgMember.persona')->find((int) $assistantTaskId)?->orgMember)) {
            $personaService = app(PersonaService::class);
            $personaBlock = $personaService->compileSystemPrompt($owner);
            $personaPolicy = $personaService->runtimePolicy($owner);
        }

        try {
            $company = Company::findOrFail($request['company_id']);

            // The flow exists (generation always starts from its builder). The
            // description may differ from the saved one while the user iterates
            // in the popup — plan against the freshly typed text.
            $flow = Flow::findOrFail($request['flow_id']);
            $flow->description = $request['description'];
            $flow->setRelation('company', $company);

            // Per-phase provider/model from the builder's generation popup.
            // Every phase is set EXPLICITLY (same rule as PlanAbCommand) so
            // .env per-phase settings cannot leak into a user-chosen combo.
            $requestedPhases = (array) ($request['phases'] ?? []);
            if ($requestedPhases !== []) {
                $default = (string) config('services.generator.provider', 'openai');
                foreach (PlannerPhases::PHASES as $phase) {
                    Config::set(
                        "services.planner.phases.{$phase}",
                        $requestedPhases[$phase] ?? ['provider' => $default, 'model' => null],
                    );
                }
            }
            $effectivePhases = app(GeneratorService::class)->resolveAllPhases();

            // Model-cost level for the agents' runtime models (popup choice).
            $level = ModelLevel::fromRequest($request['level'] ?? null);

            $lastHeartbeatAt = 0.0;
            $onProgress = function (?string $stage = null) use ($cacheKey, &$lastHeartbeatAt): void {
                $now = microtime(true);
                $current = Cache::get($cacheKey, [
                    'status' => 'pending',
                    'agents' => [],
                    'error' => null,
                ]);
                $nextStage = $stage ?: ($current['stage'] ?? 'Генериране...');

                if ($lastHeartbeatAt > 0 && ($now - $lastHeartbeatAt) < 2 && $nextStage === ($current['stage'] ?? null)) {
                    return;
                }

                $lastHeartbeatAt = $now;

                $current['status'] = 'pending';
                $current['stage'] = $nextStage;
                $current['updated_at'] = now()->timestamp;

                Cache::put($cacheKey, $current, now()->addMinutes(15));
            };

            $onProgress('Подготовка на заявката');

            $startMs = (int) (microtime(true) * 1000);
            // Обвиваме генерацията в LlmContext с generation резервацията → planner
            // редовете влизат под нея (§0.5.6). Планерът наследява reservation_id от
            // тази рамка (FlowPlannerService::runPhase merge-ва билинг полетата).
            if ($genReservation) {
                LlmContext::set([
                    'purpose' => 'org_generation',
                    'company_id' => $company->id,
                    'flow_id' => $flow->id,
                    'context_type' => 'generation',
                    'subject_type' => $genReservation->subject_type,
                    'subject_id' => $genReservation->subject_id,
                    'reservation_id' => $genReservation->id,
                ]);
            }
            try {
                $agents = $generator->generate($flow, $onProgress, $token, $level, (bool) ($request['minimal_qa'] ?? false), $personaBlock, $personaPolicy, forceWriteApproval: ! empty($request['assistant_task_id']));
            } finally {
                if ($genReservation) {
                    LlmContext::clear();
                }
            }

            if (empty($agents)) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'error' => 'Планиращият модел върна непълен план. Опитай отново или смени провайдъра за фазата «дизайн».',
                    'agents' => [],
                    'stage' => 'Генерацията се провали',
                    'updated_at' => now()->timestamp,
                ], now()->addMinutes(10));

                // Org задача: маркирай failed + refund резервацията (нищо не се харчи).
                $this->failAssistantTask($assistantTaskId, $genReservation);

                return Command::FAILURE;
            }

            $generatorMeta = [
                'label' => PlannerPhases::label($effectivePhases),
                'phases' => $effectivePhases,
            ];
            $costUsd = round((float) AgentGenerationLog::where('token', $token)->sum('cost_usd'), 4);
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            // Клиентският wizard няма builder — там фоновата команда сама записва
            // активната версия (иначе flow-ът остава без агенти/шаблон). Записваме
            // ПРЕДИ 'completed' кеша, за да не редиректне поллерът към празен flow.
            if ((bool) ($request['persist'] ?? false)) {
                $isOrgTask = ! empty($request['assistant_task_id']);
                try {
                    // Org предложение: НЕ хващай plan library (capture чак при одобрение
                    // в DecisionBoxService::approveTask) — иначе неодобрен draft влиза
                    // като „approved plan" few-shot материал.
                    $versions->createFromAgents($flow, $agents, 'Основен план', isActive: true, meta: [
                        'intent' => $generator->lastIntent(),
                        'generator' => $generatorMeta,
                        'model_level' => $level->value,
                        'cost_usd' => $costUsd,
                        'duration_ms' => $durationMs,
                    ], captureLibrary: ! $isOrgTask);

                    // Builder/wizard: активирането Е одобрението. Org задача: flow-ът
                    // ОСТАВА draft до човешко одобрение (run-safety гейтът в executor-а).
                    if (! $isOrgTask) {
                        // Scoped update — не записва in-memory description override-а.
                        Flow::whereKey($flow->id)->update(['status' => 'active']);
                    }

                    if (! empty($request['draft_id'])) {
                        FlowDraft::whereKey((int) $request['draft_id'])->update(['status' => 'completed']);
                    }

                    // Org задача (ревизиран §0.5.6): flow остава draft, задачата отива за
                    // одобрение (pending_approval) с brief — БЕЗ авто-активиране/авто-run.
                    if ($isOrgTask) {
                        $this->proposeAssistantTask((int) $request['assistant_task_id'], $flow, $genReservation);
                    }
                } catch (\Throwable $e) {
                    Log::error("[GenerateAgents] Persist failed {$token}: ".$e->getMessage(), ['exception' => $e]);

                    Cache::put($cacheKey, [
                        'status' => 'failed',
                        'error' => 'Планът се генерира, но записът се провали. Опитай отново.',
                        'agents' => [],
                        'stage' => 'Записът се провали',
                        'updated_at' => now()->timestamp,
                    ], now()->addMinutes(10));

                    $this->failAssistantTask($assistantTaskId, $genReservation);

                    return Command::FAILURE;
                }
            }

            // The builder's save-as-template dialog needs the generation meta:
            // intent (plan library pairing), generator label/phases, cost, time.
            Cache::put($cacheKey, [
                'status' => 'completed',
                'agents' => $agents,
                'intent' => $generator->lastIntent(),
                'generator' => $generatorMeta,
                'level' => $level->value,
                'cost_usd' => $costUsd,
                'duration_ms' => $durationMs,
                'error' => null,
                'stage' => 'Готово',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            Log::info("[GenerateAgents] Done — {$token}: ".count($agents).' agents'.((bool) ($request['persist'] ?? false) ? ' (persisted)' : ''));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            Log::error("[GenerateAgents] Failed {$token}: ".$e->getMessage(), ['exception' => $e]);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'agents' => [],
                'stage' => 'Генерацията се провали',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            $this->failAssistantTask($assistantTaskId, $genReservation);

            return Command::FAILURE;
        }
    }

    /** Отворената generation резервация за задачата (детерминистичен субект, §0.5.6). */
    private function generationReservation(int $taskId): ?CreditReservation
    {
        return CreditReservation::where('context_type', 'generation')
            ->where('subject_type', (new AssistantTask)->getMorphClass())
            ->where('subject_id', $taskId)
            ->where('status', 'reserved')
            ->latest('id')
            ->first();
    }

    /**
     * Успешна генерация на org задача: предпазливите служители оставят draft за човешко
     * одобрение, а силно автономните активират flow-а веднага. Settle-ва generation
     * резервацията по реалните planner редове. Бриф + хроника са best-effort.
     */
    private function proposeAssistantTask(int $taskId, Flow $flow, ?CreditReservation $reservation): void
    {
        $task = AssistantTask::with('orgMember.persona')->find($taskId);
        if (! $task) {
            return;
        }

        $autoApprove = $task->approval_policy === 'auto';
        if ($autoApprove) {
            $flow->update(['status' => 'active']);
        }

        $task->update([
            'flow_id' => $flow->id,
            'status' => $autoApprove ? 'ready' : 'pending_approval',
            'approved_at' => $autoApprove ? now() : null,
        ]);

        if ($reservation) {
            $meter = app(CreditMeterService::class);
            $meter->settle($reservation, $meter->actualFor($reservation));
        }

        // Бриф от draft flow-а — best-effort (flow-ът вече е генериран; не проваляй).
        try {
            app(TaskProposalBriefService::class)->build($task->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        if ($autoApprove && ($version = $flow->activeVersion)) {
            try {
                app(PlanLibraryService::class)->captureApprovedPlan($version->fresh());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Хроника: предложена или автоматично активирана задача (best-effort).
        try {
            $company = Company::find($task->orgMember?->company_id);
            $company?->orgEvents()->create([
                'type' => $autoApprove ? 'task_approved' : 'task_proposed',
                'org_version_id' => $company->active_org_version_id,
                'org_member_id' => $task->org_member_id,
                'subject_type' => $task->getMorphClass(),
                'subject_id' => $task->id,
                'summary' => ($autoApprove ? 'Автоматично активирана задача: ' : 'Предложена задача: ').$task->title,
                'meta' => ['task_id' => $task->id, 'flow_id' => $flow->id, 'approval_policy' => $task->approval_policy],
                'actor' => 'assistant',
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($autoApprove && $task->run_after_generate) {
            try {
                app(TaskRunService::class)->launchReadyRun($task->fresh());
                $task->update(['run_after_generate' => false]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Провал на генерация на org задача: status='failed' + пълен refund на резервацията. */
    private function failAssistantTask(?int $taskId, ?CreditReservation $reservation): void
    {
        if ($taskId && ($task = AssistantTask::find($taskId))) {
            $task->update(['status' => 'failed']);
        }

        if ($reservation) {
            app(CreditMeterService::class)->refund($reservation);
        }
    }
}
