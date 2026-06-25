<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\OrgInterviewTurnJob;
use App\Models\BusinessProfile;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Интервюто на Управителя (§1.4) — чат с token-poll, точно като клиентския wizard.
 */
class InterviewController extends Controller
{
    public function show()
    {
        $company = $this->company();
        if (! $company->manager) {
            return redirect()->route('client.org.casting');
        }

        $profile = BusinessProfile::firstOrCreate(
            ['company_id' => $company->id],
            ['status' => 'interviewing'],
        );

        return view('client.org.interview', [
            'profile' => $profile,
            'manager' => $company->manager,
        ]);
    }

    /** Един ход: натрупва отговора и пуска OrgInterviewTurnJob → token. */
    public function send(Request $request): JsonResponse
    {
        $company = $this->company();
        $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'answer' => ['nullable', 'array'],
        ]);

        $profile = BusinessProfile::firstOrCreate(
            ['company_id' => $company->id],
            ['status' => 'interviewing'],
        );

        // Натрупване на структуриран отговор (като wizard-а).
        $answer = (array) $request->input('answer', []);
        if (filled($answer['key'] ?? null)) {
            $values = array_values(array_filter(array_map('strval', (array) ($answer['values'] ?? []))));
            $other = trim((string) ($answer['other'] ?? ''));
            if ($other !== '') {
                $values[] = $other;
            }
            $answers = (array) $profile->interview_answers;
            $answers[$answer['key']] = $values;
            $profile->update(['interview_answers' => $answers]);
            $userInput = 'Отговор на «'.$answer['key'].'»: '.(implode(', ', $values) ?: '—');
        } else {
            $userInput = trim((string) $request->input('message'));
        }

        $token = (string) Str::uuid();
        Cache::put("org_interview_{$token}", ['status' => 'pending', 'stage' => 'Мисля…', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        OrgInterviewTurnJob::dispatch($token, $profile->id, $userInput)->onQueue('org');

        return response()->json(['token' => $token]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("org_interview_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече. Изпрати отново.'], 404);
        }

        return response()->json($result);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
