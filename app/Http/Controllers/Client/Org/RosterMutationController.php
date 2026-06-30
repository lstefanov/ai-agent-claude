<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\IgniteOrganizationJob;
use App\Models\Company;
use App\Models\Director;
use App\Models\OrgMember;
use App\Models\User;
use App\Services\Org\OrgMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Жива редакция на roster-а (apply immediately, §7.3): добави асистент/отдел, редактирай отдел,
 * премахни член. Тънък — делегира на OrgMutationService. Бавното LLM генериране на персона/отдел
 * минава през DesignController (cache + token-poll); тук пристига ГОТОВИЯТ блок за материализация.
 */
class RosterMutationController extends Controller
{
    public function __construct(private OrgMutationService $mutations) {}

    /** Добавя готов генериран асистент под избран директор → нова версия + ignition. */
    public function addAssistant(Request $request): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'director_member_id' => ['required', 'integer'],
            'assistant' => ['required', 'array'],
        ]);

        // Целевият директор трябва да е активен член на тази фирма.
        $director = $company->members()->where('kind', 'director')->find($data['director_member_id']);
        abort_unless($director !== null, 422);

        $version = $this->mutations->hireIntoDepartment($company, (int) $data['director_member_id'], $data['assistant'], $this->user());
        if (! $version) {
            return response()->json(['ok' => false, 'error' => 'Отделът вече не съществува.'], 422);
        }
        IgniteOrganizationJob::dispatch($version->id)->onQueue('org');

        return response()->json(['ok' => true, 'reload' => true]);
    }

    /** Добавя готов генериран отдел (директор + асистенти) → нова версия + ignition. */
    public function addDepartment(Request $request): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'department' => ['required', 'array'],
            'department.director' => ['required', 'array'],
        ]);

        $version = $this->mutations->addDepartmentFromDraft($company, $data['department'], $this->user());
        if (! $version) {
            return response()->json(['ok' => false, 'error' => 'Неуспешно създаване на отдела.'], 422);
        }
        IgniteOrganizationJob::dispatch($version->id)->onQueue('org');

        return response()->json(['ok' => true, 'reload' => true]);
    }

    /** Редактира отдела in-place (title/mandate/priorities) — без нова версия. */
    public function updateDepartment(Request $request, Director $director): JsonResponse
    {
        $company = $this->company();
        abort_unless($director->org_version_id === $company->active_org_version_id, 403);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'domain' => ['nullable', 'string', 'max:120'],
            'mandate' => ['nullable', 'string', 'max:1000'],
            'priorities' => ['nullable', 'array'],
            'priorities.*' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'in:purple,teal,coral,blue,amber,pink,green'],
        ]);

        $ok = $this->mutations->updateDepartment(
            $company,
            (int) $director->org_member_id,
            (string) ($data['title'] ?? ''),
            (string) ($data['domain'] ?? ''),
            (string) ($data['mandate'] ?? ''),
            array_values((array) ($data['priorities'] ?? [])),
            $data['color'] ?? null,
            $this->user(),
        );
        if (! $ok) {
            return response()->json(['ok' => false, 'error' => 'Неуспешна редакция.'], 422);
        }

        return response()->json(['ok' => true, 'reload' => true]);
    }

    /** Премахва член: асистент (fireMember) или директор + асистентите му (fireDepartment). */
    public function removeMember(OrgMember $member): JsonResponse
    {
        $company = $this->company();
        abort_unless($member->company_id === $company->id, 403);

        if ($member->kind === 'manager') {
            return response()->json(['ok' => false, 'error' => 'Управителят не може да бъде премахнат.'], 422);
        }

        $version = $member->kind === 'director'
            ? $this->mutations->fireDepartment($company, $member->id, $this->user())
            : $this->mutations->fireMember($company, $member->id, $this->user());

        if (! $version) {
            return response()->json(['ok' => false, 'error' => 'Нужен е поне един отдел.'], 422);
        }

        return response()->json(['ok' => true, 'reload' => true]);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }

    private function user(): ?User
    {
        return User::find(session('client_user_id'));
    }
}
