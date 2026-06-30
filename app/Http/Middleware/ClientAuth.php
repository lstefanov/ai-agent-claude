<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\Org\DecisionBoxService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientAuth
{
    /**
     * Клиентска „fake" сесийна аутентикация (preview).
     * Без `client_company_id` → редирект към входа. Споделя текущата фирма/
     * потребител към всички клиентски view-та.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = session('client_company_id');
        if (! $companyId) {
            return redirect()->route('client.login');
        }

        $company = Company::find($companyId);
        if (! $company) {
            session()->forget(['client_company_id', 'client_user_id']);

            return redirect()->route('client.login');
        }

        view()->share('currentCompany', $company);
        view()->share('currentUser', User::find(session('client_user_id')));
        view()->share('pendingProposalsCount', app(DecisionBoxService::class)->pendingCount($company));

        return $next($request);
    }
}
