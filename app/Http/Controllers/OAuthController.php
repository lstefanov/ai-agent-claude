<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;

/**
 * OAuth flow за MCP конекторите — глобален FlowAI app (Google). Записва encrypted
 * токени в company_connectors.
 *
 * STATELESS: company+service+origin пътуват в КРИПТИРАН `state` параметър
 * (round-trip през доставчика), НЕ в сесия. Така единственият callback
 * (регистрираният redirect URI на flowai.local.com) обслужва и админ, и клиентския
 * портал (различен субдомейн/сесия) — `origin` решава къде да върнем. Криптирането
 * с APP_KEY прави state-а tamper-proof + носи изтичане.
 */
class OAuthController extends Controller
{
    // Google услуга → нужните scopes + connector_type. openid/email/profile дават
    // акаунта (display_name). Дай само нужните scopes per услуга.
    private const GOOGLE_SERVICES = [
        'gmail' => [
            'type' => 'gmail',
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/gmail.modify',
            ],
        ],
        'google_sheets' => [
            'type' => 'google_sheets',
            'scopes' => ['https://www.googleapis.com/auth/spreadsheets'],
        ],
        'google_drive' => [
            'type' => 'google_drive',
            'scopes' => ['https://www.googleapis.com/auth/drive'],
        ],
        'google_docs' => [
            'type' => 'google_docs',
            'scopes' => [
                'https://www.googleapis.com/auth/documents',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
        ],
        'google_calendar' => [
            'type' => 'google_calendar',
            'scopes' => [
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar.events',
            ],
        ],
    ];

    // ─────────────────────────── Google ───────────────────────────

    /** Админ старт: фирмата идва от route-binding (админът действа върху всяка фирма). */
    public function googleRedirect(Company $company, Request $request): RedirectResponse
    {
        return $this->startGoogleOAuth($company, (string) $request->query('service', 'gmail'), 'admin');
    }

    /** Клиентски старт: фирмата идва от сесията (никога от URL). */
    public function clientGoogleRedirect(Request $request): RedirectResponse
    {
        $company = Company::findOrFail((int) session('client_company_id'));

        return $this->startGoogleOAuth($company, (string) $request->query('service', 'gmail'), 'client');
    }

    private function startGoogleOAuth(Company $company, string $service, string $origin): RedirectResponse
    {
        $cfg = self::GOOGLE_SERVICES[$service] ?? self::GOOGLE_SERVICES['gmail'];

        return Socialite::driver('google')
            ->stateless()
            ->scopes([...$cfg['scopes'], 'openid', 'email', 'profile'])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $this->encodeState($company->id, $service, $origin),
            ])
            ->redirect();
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $state = $this->decodeState((string) $request->query('state', ''));
        if ($state === null) {
            return redirect()->route('companies.index')->with('success', 'OAuth връзката изтече или е невалидна — опитай пак.');
        }

        $company = Company::find($state['c']);
        if (! $company) {
            return redirect()->route('companies.index')->with('success', 'Фирмата не е намерена.');
        }

        $service = (string) $state['s'];
        $origin = (string) $state['o'];
        $type = self::GOOGLE_SERVICES[$service]['type'] ?? 'gmail';

        if ($request->query('error')) {
            return $this->fail($company, $origin, 'Връзката с Google е отказана: '.$request->query('error'));
        }

        try {
            $user = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return $this->fail($company, $origin, 'Google OAuth грешка: '.$e->getMessage());
        }

        $email = $user->getEmail();

        $company->connectors()->updateOrCreate(
            ['connector_type' => $type, 'display_name' => $email],
            [
                'auth_type' => 'oauth2',
                'credentials' => [
                    'access_token' => $user->token,
                    'refresh_token' => $user->refreshToken,
                    'expires_at' => now()->timestamp + (int) ($user->expiresIn ?? 3600),
                ],
                'scopes' => $this->googleGrantedScopes($user, $service),
                'status' => 'active',
                'last_error' => null,
            ],
        );

        return $this->success($company, $origin, $type, (string) $email);
    }

    private function googleGrantedScopes(object $user, string $service): array
    {
        $scope = $user->accessTokenResponseBody['scope'] ?? '';

        return $scope !== '' ? explode(' ', $scope) : (self::GOOGLE_SERVICES[$service]['scopes'] ?? []);
    }

    // ─────────────────────────── helpers ───────────────────────────

    /** Криптиран state (tamper-proof + изтичане), пътува през доставчика. */
    private function encodeState(int $companyId, string $service, string $origin = 'admin'): string
    {
        return Crypt::encryptString(json_encode([
            'c' => $companyId,
            's' => $service,
            'o' => $origin,
            'e' => now()->addMinutes(15)->timestamp,
        ]));
    }

    /** @return array{c:int,s:string,o:string}|null */
    private function decodeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || empty($data['c']) || ($data['e'] ?? 0) < now()->timestamp) {
            return null;
        }

        return [
            'c' => (int) $data['c'],
            's' => (string) ($data['s'] ?? 'gmail'),
            'o' => (string) ($data['o'] ?? 'admin'),
        ];
    }

    /** Успешен връзка — админ: flash на callback хоста; клиент: ?connected на портала. */
    private function success(Company $company, string $origin, string $type, string $email): RedirectResponse
    {
        if ($origin === 'client') {
            return redirect(route('client.org.integrations').'?connected='.urlencode($type));
        }

        return redirect($this->backToConnectors($company))
            ->with('success', ucfirst(str_replace('google_', 'Google ', $type))." свързан: {$email}");
    }

    /** Провал/отказ — същият split като success(). */
    private function fail(Company $company, string $origin, string $msg): RedirectResponse
    {
        if ($origin === 'client') {
            return redirect(route('client.org.integrations').'?error='.urlencode($msg));
        }

        return redirect($this->backToConnectors($company))->with('success', $msg);
    }

    /**
     * Релативен URL към админ connectors страницата — остава на ХОСТА на callback-а
     * (flowai.local.com), за да оцелее flash съобщението (същата сесия/домейн),
     * вместо route() който сочи към APP_URL (друг домейн).
     */
    private function backToConnectors(Company $company): string
    {
        return route('companies.connectors.index', $company, absolute: false);
    }
}
