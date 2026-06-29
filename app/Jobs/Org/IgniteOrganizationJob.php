<?php

namespace App\Jobs\Org;

use App\Models\AssistantTask;
use App\Models\OrgVersion;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\TaskRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Запалване на одобрения екип (§ignition) — `org` queue. Генерира seed задачите на новата
 * активна версия → влизат в Кутията за решения (pending_approval, с brief). БЕЗ авто-пускане
 * (Q1: човекът одобрява; Q3: първата версия винаги при човек). Идемпотентно (само proposed +
 * без flow → re-dispatch е безопасен) и кредитно-безопасно (резервацията е вътре в generate()).
 */
class IgniteOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $orgVersionId) {}

    public function handle(TaskRunService $runner, AutonomousBudgetService $budget): void
    {
        $version = OrgVersion::with('company')->find($this->orgVersionId);
        $company = $version?->company;
        // Само за все още активната версия — по-ново одобрение я е заменило → не пали стара.
        if (! $version || ! $company || $company->active_org_version_id !== $version->id) {
            return;
        }

        $assistantMemberIds = $version->assistants()->pluck('org_member_id')->filter()->unique();
        $tasks = AssistantTask::whereIn('org_member_id', $assistantMemberIds)
            ->where('status', 'proposed')
            ->whereNull('flow_id')   // вече запалена задача (има flow) не се пали повторно
            ->get();

        $started = 0;
        foreach ($tasks as $task) {
            if (! $budget->allows($company, 'ignition')) {
                break;
            }
            try {
                $runner->generate($task, runAfterGenerate: false, origin: 'autonomous');
                $started++;
            } catch (InsufficientCreditsException) {
                $company->orgEvents()->create([
                    'type' => 'review',
                    'org_version_id' => $version->id,
                    'summary' => 'Запалването спря — недостатъчно кредити. Презаредете и продължете от Кутията.',
                    'actor' => 'manager',
                ]);
                break;
            } catch (\Throwable $e) {
                Log::warning('[Ignite] task '.$task->id.' failed: '.$e->getMessage());
            }
        }

        if ($started > 0) {
            $company->orgEvents()->create([
                'type' => 'review',
                'org_version_id' => $version->id,
                'org_member_id' => $company->manager?->id,
                'summary' => "Планът се генерира ({$started} задачи) — следете Кутията за решения.",
                'actor' => 'manager',
            ]);
        }
    }
}
