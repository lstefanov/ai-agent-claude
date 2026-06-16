<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\ClientWizardTurnJob;
use App\Models\Company;
use App\Models\FlowDraft;
use App\Models\FlowDraftMessage;
use App\Services\AgentGenerationLauncher;
use App\Services\ClientFlowWizardService;
use App\Services\FlowDescriptionImprover;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlowWizardController extends Controller
{
    /** Отваря (или продължава) активна чернова за текущия потребител. */
    public function create(ClientFlowWizardService $wizard)
    {
        $companyId = (int) session('client_company_id');
        $userId = session('client_user_id');

        $draft = FlowDraft::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereIn('status', ['interviewing', 'ready'])
            ->latest()
            ->first();

        if (! $draft) {
            $draft = FlowDraft::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'session' => (string) Str::uuid(),
                'status' => 'interviewing',
            ]);
        }

        return view('client.flows.wizard', [
            'draft' => $draft,
            'available' => $wizard->isAvailable(),
        ]);
    }

    /**
     * „Нов чат" — изоставя текущата активна чернова (create() я преотваря, докато е
     * interviewing/ready, дори след logout) и отваря свежа.
     */
    public function startNew(): RedirectResponse
    {
        FlowDraft::where('company_id', (int) session('client_company_id'))
            ->where('user_id', session('client_user_id'))
            ->whereIn('status', ['interviewing', 'ready'])
            ->update(['status' => 'abandoned']);

        return redirect()->route('client.flows.create');
    }

    /** Изпраща съобщение/отговор → стартира фонов ход на бота. */
    public function send(Request $request, ClientFlowWizardService $wizard): JsonResponse
    {
        $request->validate([
            'draft_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:6000'],
            'answer' => ['nullable', 'array'],
            'description' => ['nullable', 'string', 'max:20000'],
        ]);

        $draft = FlowDraft::findOrFail($request->integer('draft_id'));
        $this->authorizeCompany($draft->company_id);

        if (! $wizard->isAvailable()) {
            return response()->json([
                'message' => 'Създателят изисква cloud AI с API ключ. Свържи се с администратор.',
            ], 503);
        }

        // Съдържание на потребителския ход: свободен текст ИЛИ избран отговор.
        $answer = (array) $request->input('answer', []);
        $payload = null;
        if ($answer !== [] && filled($answer['key'] ?? null)) {
            $values = array_values(array_filter(array_map('strval', (array) ($answer['values'] ?? []))));
            $other = trim((string) ($answer['other'] ?? ''));
            if ($other !== '') {
                $values[] = $other;
            }
            $content = 'Отговор за «'.$answer['key'].'»: '.(implode(', ', $values) ?: '—');
            $payload = ['key' => $answer['key'], 'values' => $values];

            // Натрупай в draft->answers за грунд на бота.
            $answers = (array) $draft->answers;
            $answers[$answer['key']] = $values;
            $draft->answers = $answers;
            $draft->save();
        } else {
            $content = trim((string) $request->input('message'));
            if ($content === '') {
                return response()->json(['message' => 'Празно съобщение.'], 422);
            }
        }

        // Клиентът може да е редактирал черновата вдясно — ботът да стъпи върху нея.
        if ($request->filled('description')) {
            $draft->update(['description' => (string) $request->input('description')]);
        }

        $userMessage = FlowDraftMessage::create([
            'flow_draft_id' => $draft->id,
            'role' => 'user',
            'content' => $content,
            'payload' => $payload,
            'status' => 'completed',
        ]);

        $reply = FlowDraftMessage::create([
            'flow_draft_id' => $draft->id,
            'role' => 'assistant',
            'status' => 'pending',
        ]);

        $token = (string) Str::uuid();
        Cache::put("wizard_{$token}", ['status' => 'pending', 'stage' => 'Мисля…', 'updated_at' => now()->timestamp], now()->addMinutes(15));

        ClientWizardTurnJob::dispatch($token, $draft->id, $userMessage->id, $reply->id);

        return response()->json(['token' => $token, 'draft_id' => $draft->id]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("wizard_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Изтекъл токен. Изпрати съобщението отново.'], 404);
        }

        return response()->json($result);
    }

    public function history(FlowDraft $draft): JsonResponse
    {
        $this->authorizeCompany($draft->company_id);

        $messages = $draft->messages()
            ->where('status', '!=', 'pending')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->status === 'failed' ? ($m->error ?: 'Грешка.') : $m->content,
                'failed' => $m->status === 'failed',
                'question' => $m->role === 'assistant' ? data_get($m->payload, 'question') : null,
            ])
            ->values();

        return response()->json([
            'draft_id' => $draft->id,
            'title' => $draft->title,
            'description' => $draft->description,
            'status' => $draft->status,
            'messages' => $messages,
        ]);
    }

    /** „Готово, Генерирай" → създава Flow и пуска генерацията на агенти. */
    public function build(Request $request, FlowDraft $draft, AgentGenerationLauncher $launcher): JsonResponse
    {
        $this->authorizeCompany($draft->company_id);

        $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'speed' => ['nullable', 'in:fast,balanced,economic'],
        ]);

        $title = trim((string) ($request->input('title') ?: $draft->title)) ?: 'Нов Flow';
        $description = trim((string) ($request->input('description') ?: $draft->description));

        if (mb_strlen($description) < 10) {
            return response()->json(['message' => 'Описанието е твърде кратко. Добави още детайли или продължи разговора.'], 422);
        }

        $company = Company::findOrFail($draft->company_id);

        $flow = $company->flows()->create([
            'name' => $title,
            'description' => $description,
            'status' => 'draft',
        ]);

        $level = $this->resolveLevel($request->input('speed'), $company);
        // A4: клиентските flows минават само с финален QA gate (без междинни).
        // persist: клиентът няма builder — фоновата команда сама записва активната
        // версия (иначе flow-ът остава без агенти/шаблон), и маркира черновата готова.
        $token = $launcher->launch($company->id, $flow->id, $title, $description, $level, [], minimalQa: true, persist: true, draftId: $draft->id);

        $draft->update([
            'status' => 'building',
            'flow_id' => $flow->id,
            'title' => $title,
            'description' => $description,
        ]);

        return response()->json([
            'token' => $token,
            'flow_id' => $flow->id,
            'status_url' => route('client.wizard.generation-status', $token),
            'redirect_url' => route('client.flows.show', $flow),
        ]);
    }

    /** C5: редактирай минал отговор (само script режим) — върни въпроса наново. */
    public function revise(Request $request, FlowDraft $draft, ClientFlowWizardService $wizard): JsonResponse
    {
        $this->authorizeCompany($draft->company_id);
        $request->validate(['key' => ['required', 'string', 'max:60']]);

        if (data_get($draft->script, 'mode') !== 'script') {
            return response()->json(['message' => 'Редакция е възможна само в режим със стъпки.'], 422);
        }

        $result = $wizard->reviseTo($draft, (string) $request->input('key'));
        if ($result === null) {
            return response()->json(['message' => 'Въпросът не е намерен.'], 404);
        }

        return response()->json($result);
    }

    /** „Подобри с AI" — пренаписва описанието преди генерация (auto-on-Generate). */
    public function improveDescription(Request $request, ClientFlowWizardService $wizard, FlowDescriptionImprover $improver): JsonResponse
    {
        $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:20000'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $wizard->isAvailable()) {
            return response()->json(['error' => 'AI услугата не е достъпна в момента.'], 503);
        }

        try {
            $improved = $improver->improve((string) $request->input('title', ''), (string) $request->input('description'));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Не успяхме да подобрим описанието.'], 503);
        }

        return response()->json(['improved' => $improved]);
    }

    /** Same-origin поллинг на генерацията — без технически детайли за клиента. */
    public function generationStatus(string $token): JsonResponse
    {
        $result = Cache::get("agent_gen_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Изтекъл токен.'], 404);
        }

        return response()->json([
            'status' => $result['status'] ?? 'pending',
            'stage' => $result['stage'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Клиентският избор „Скорост" → ModelLevel. „Бързо" = евтин cloud (паралелно,
     * бързо); „Икономично" = предимно локално (по-бавно, по-евтино). Без избор:
     * настройката на фирмата, иначе „бързо" по подразбиране. Капва се на high —
     * клиентът не може да тригерне ultra/god (скъп flagship разход).
     */
    private function resolveLevel(?string $speed, Company $company): string
    {
        $map = ['fast' => 'high', 'balanced' => 'medium', 'economic' => 'low'];

        if ($speed !== null && isset($map[$speed])) {
            return $map[$speed];
        }

        return ModelLevel::fromRequest($company->settings['model_level'] ?? 'high')->value;
    }

    private function authorizeCompany(?int $companyId): void
    {
        abort_unless($companyId === (int) session('client_company_id'), 403);
    }
}
