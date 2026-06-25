<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;

/**
 * Дневник на куестове (§3.3): задачите като куестове — статус/изпълнител/прогрес.
 */
class QuestController extends Controller
{
    public function index()
    {
        $company = Company::findOrFail((int) session('client_company_id'));

        $assistantIds = $company->members()
            ->where('kind', 'assistant')->where('status', 'active')->pluck('id');

        $tasks = AssistantTask::whereIn('org_member_id', $assistantIds)
            ->with('orgMember.persona', 'flow')
            ->orderByRaw("FIELD(status,'running','ready','generating','proposed','failed','disabled')")
            ->get();

        return view('client.org.quests', ['company' => $company, 'tasks' => $tasks]);
    }
}
