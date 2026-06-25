<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;

/**
 * Интеграции рейл (§5.2) — конекторите на фирмата като видим инвентар. OAuth flow-ът
 * вече съществува (CompanyConnectorController); тук само показваме статуса + act задачите.
 */
class IntegrationsController extends Controller
{
    public function index()
    {
        $company = Company::findOrFail((int) session('client_company_id'));

        $actTasks = AssistantTask::whereIn('org_member_id', $company->members()->pluck('id'))
            ->whereIn('act_mode', ['act', 'mixed'])
            ->with('orgMember.persona')
            ->get();

        return view('client.org.integrations', [
            'company' => $company,
            'connectors' => $company->connectors,
            'actTasks' => $actTasks,
            'actEnabled' => (bool) config('organization.act.enabled'),
        ]);
    }
}
