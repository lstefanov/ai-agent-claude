<?php

namespace App\Services\Org;

use App\Models\AgentGenerationLog;
use App\Models\AgentTemplate;
use App\Models\AssistantNote;
use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\FlowDraft;
use App\Models\LlmRequest;
use App\Models\OrgProposal;
use App\Models\PlanLibraryEntry;
use Illuminate\Support\Facades\DB;

/**
 * Нулиране на компания (бързи експерименти): трие ВСИЧКО свързано с компанията,
 * освен секцията „Знания" (knowledge_*) и потребителските акаунти (users).
 *
 * Триенето е в една транзакция. Повечето деца падат по DB FK cascade при триене на
 * родителя (както разчита и онбордингът). Каскадите от знанията към runs/ресурси са
 * nullOnDelete, така че фактите/празнините оцеляват — само връзката се занулява.
 *
 * Запазва се: companies (самия ред), users, всички knowledge таблици, глобалните
 * таблици (plans, llm_models, persona_archetypes, web_page кешове, org_blueprints) и
 * глобалните (company_id = NULL) редове в agent_templates/agent_generation_logs/llm_requests/plan_library.
 */
class OrgResetService
{
    public function reset(Company $company): void
    {
        DB::transaction(function () use ($company) {
            // 1. Освобождаваме FK-а към активната версия + ресет на company-level настройки,
            //    за да може да трием org версиите и рутирането да падне на casting.
            $company->update([
                'active_org_version_id' => null,
                'auton_daily_credits' => -1,
                'auton_daily_percent' => -1,
            ]);

            // 2. Flows → cascade: flow_versions, flow_nodes, flow_edges, flow_runs → node_runs,
            //    flow_memories, flow_eval_cases, flow_eval_runs, assistant_messages (flow_id).
            //    Знанията оцеляват: knowledge_facts/gaps.flow_run_id са nullOnDelete.
            $company->flows()->delete();

            // 3. Org структура.
            $company->members()->delete();        // cascade: персони, member_chats→member_messages, задачи→knowledge_requirements, director/assistant placements
            $company->orgVersions()->delete();    // cascade: останали directors/assistants
            $company->businessProfile()->delete();
            $company->orgEvents()->delete();
            OrgProposal::where('company_id', $company->id)->delete();   // Решения

            // 4. Останали company-scoped артефакти (assistant_messages вече падна с flows).
            FlowDraft::where('company_id', $company->id)->delete();     // cascade: flow_draft_messages
            AssistantNote::where('company_id', $company->id)->delete();
            AgentTemplate::where('company_id', $company->id)->delete(); // само на тази компания; NULL = глобален шаблон
            PlanLibraryEntry::where('company_id', $company->id)->delete();
            AgentGenerationLog::where('company_id', $company->id)->delete();
            LlmRequest::where('company_id', $company->id)->delete();

            // 5. Интеграции.
            $company->connectors()->delete();     // cascade: connector_tool_logs

            // 6. Билинг (FK-safe ред). Порфейлът се пресъздава автоматично (firstOrCreate)
            //    при следващо зареждане; абонаментът се избира наново от потребителя.
            $company->subscription()->delete();
            CreditReservation::where('company_id', $company->id)->delete();
            CreditLedgerEntry::where('company_id', $company->id)->delete();
            $company->creditWallet()->delete();   // cascade: остатъчен credit_ledger
        });
    }
}
