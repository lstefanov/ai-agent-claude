<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\OrgReviewJob;
use App\Models\Company;
use App\Models\OrgMember;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\OrgChronicleService;
use App\Services\Org\OrgGraphService;
use App\Support\ChronicleType;
use App\Support\OrgReviewLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * „Един граф, няколко лещи" (§6): едни и същи director/assistant/task записи, три
 * изгледа (Roster / Skill Tree / Карта) над общ JSON.
 */
class OrgGraphController extends Controller
{
    public function __construct(private OrgGraphService $graph) {}

    public function graph(): JsonResponse
    {
        return response()->json($this->graph->build($this->company()));
    }

    public function roster()
    {
        $company = $this->company();

        return view('client.org.roster', ['graph' => $this->graph->build($company), 'company' => $company]);
    }

    public function skillTree()
    {
        $company = $this->company();
        $graph = $this->graph->build($company);

        return view('client.org.skill-tree', [
            'graph' => $graph,
            'company' => $company,
            'lens' => $this->graph->skillLens($graph),
        ]);
    }

    /** Текущ поток (Леща 3, §6) — кои асистенти имат активни runs. */
    public function live()
    {
        $company = $this->company();

        return view('client.org.live', ['graph' => $this->graph->build($company), 'company' => $company]);
    }

    /** Хроника на фирмата (§7.4) — единен поток на дейността + control-room табло. */
    public function chronicle(Request $request, OrgChronicleService $chronicle)
    {
        $company = $this->company();
        $params = $this->chronicleParams($request);

        return view('client.org.chronicle', [
            'company' => $company,
            'page' => $chronicle->feed($company, $params),
            'stats' => $chronicle->stats($company, $params['since']),
            'breakdown' => $chronicle->breakdown($company, $params['since']),
            'members' => $this->chronicleMembers($company),
        ]);
    }

    /** AJAX страница от потока (филтри/период/търсене + „зареди още") → рендиран фрагмент. */
    public function chronicleFeed(Request $request, OrgChronicleService $chronicle): JsonResponse
    {
        $company = $this->company();
        $params = $this->chronicleParams($request);
        $page = $chronicle->feed($company, $params);

        $data = [
            'feed_html' => view('client.org._chronicle-feed', [
                'items' => $page['items'],
                'afterDay' => $request->query('after_day'),
            ])->render(),
            'next_cursor' => $page['next_cursor'],
            'last_day' => $page['last_day'],
            'empty' => $page['items'] === [] && $params['cursor'] === null,
        ];

        // KPI/хистограма/разбивка се смятат само на първа страница (без cursor) → евтино „зареди още".
        if ($params['cursor'] === null) {
            $data['stats_html'] = view('client.org._chronicle-stats', [
                'stats' => $chronicle->stats($company, $params['since']),
            ])->render();
            $data['breakdown_html'] = view('client.org._chronicle-breakdown', [
                'breakdown' => $chronicle->breakdown($company, $params['since']),
            ])->render();
        }

        return response()->json($data);
    }

    /**
     * Нормализира заявката към потока (period/groups/member/q/cursor) в общ shape.
     *
     * @return array{since:Carbon,end:Carbon,groups:array<int,string>,member:?int,q:?string,cursor:?array{ts:int,rank:int,id:int}}
     */
    private function chronicleParams(Request $request): array
    {
        $period = in_array($request->query('period'), ['today', '7d', '30d'], true)
            ? $request->query('period')
            : '30d';

        $since = match ($period) {
            'today' => Carbon::today(),
            '7d' => Carbon::now()->subDays(7),
            default => Carbon::now()->subDays(30),
        };

        $groups = array_values(array_intersect(
            (array) $request->query('groups', []),
            array_keys(ChronicleType::GROUPS),
        ));

        $member = $request->query('member');
        $q = trim((string) $request->query('q', ''));

        return [
            'since' => $since,
            'end' => Carbon::now(),
            'groups' => $groups,
            'member' => is_numeric($member) ? (int) $member : null,
            'q' => $q === '' ? null : $q,
            'cursor' => OrgChronicleService::decodeCursor($request->query('cursor')),
        ];
    }

    /** Активните членове за филтъра по актьор. */
    private function chronicleMembers(Company $company): Collection
    {
        return OrgMember::where('company_id', $company->id)
            ->whereIn('kind', ['manager', 'director', 'assistant'])
            ->where('status', 'active')
            ->with('persona')
            ->get()
            ->map(fn (OrgMember $m) => ['id' => $m->id, 'name' => $m->fullName()])
            ->values();
    }

    /** Ръчно „Пусни ревю сега" (§7.1) → OrgReviewJob. */
    public function reviewNow(AutonomousBudgetService $budget): JsonResponse
    {
        $company = $this->company();

        if (! $company->active_org_version_id) {
            return response()->json([
                'ok' => false,
                'message' => 'Няма активна организация — завърши онбординга преди ревю.',
            ], 422);
        }

        if (! $budget->allows($company, 'org_review')) {
            return response()->json([
                'ok' => false,
                'message' => 'Дневният лимит за автономни действия е достигнат.',
            ], 429);
        }

        if (! OrgReviewLock::acquire($company->id)) {
            return response()->json([
                'ok' => false,
                'message' => 'Ревю вече тече или е пуснато наскоро — изчакай малко.',
            ], 409);
        }

        OrgReviewJob::dispatch($company->id)->onQueue('org');

        return response()->json([
            'ok' => true,
            'message' => 'Ревюто е пуснато — предложенията ще се появят в Кутията (обикновено за 1–3 мин).',
        ]);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
