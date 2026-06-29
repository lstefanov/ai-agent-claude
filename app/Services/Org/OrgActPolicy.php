<?php

namespace App\Services\Org;

use App\Models\Company;

/**
 * Може ли фирмата да изпълнява РЕАЛНИ действия (act-mode) сега. Три условия (всичките):
 * глобален master (ORG_ACT_ENABLED) + per-company opt-in (companies.act_enabled) + поне един
 * свързан (status=active) конектор. Без някое от тях → act задачите остават „чернова на
 * действието" (NodeExecutorService), без реален страничен ефект. Human_approval гейтът пред
 * write е НЕЗАВИСИМ от това (винаги активен) — тук решаваме само чернова vs реален call.
 */
class OrgActPolicy
{
    public static function enabledFor(?Company $company): bool
    {
        return $company !== null
            && (bool) config('organization.act.enabled')
            && (bool) $company->act_enabled
            && $company->connectors()->active()->exists();
    }
}
