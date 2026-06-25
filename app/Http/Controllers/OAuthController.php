<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

/**
 * OAuth flow за MCP конекторите — глобален FlowAI app (Google/Slack). Записва
 * encrypted токени в company_connectors.
 *
 * STATELESS: company+service пътуват в КРИПТИРАН `state` параметър (round-trip
 * през доставчика), НЕ в сесия. Така callback-ът работи дори когато redirect URI
 * е на друг домейн (Google отхвърля .local → ползваме flowai.local.com, друг
 * origin от APP_URL flowai.local → сесията там е празна). Криптирането с APP_KEY
 * прави state-а tamper-proof + носи изтичане.
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
    ];

    private const SLACK_SCOPES = ['channels:read', 'channels:history', 'chat:write', 'files:write'];

    // ─────────────────────────── Google ───────────────────────────

    public function googleRedirect(Company $company, Request $request): RedirectResponse
    {
        $service = (string) $request->query('service', 'gmail');
        $cfg = self::GOOGLE_SERVICES[$service] ?? self::GOOGLE_SERVICES['gmail'];

        return Socialite::driver('google')
            ->stateless()
            ->scopes([...$cfg['scopes'], 'openid', 'email', 'profile'])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $this->encodeState($company->id, $service),
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
        $type = self::GOOGLE_SERVICES[$service]['type'] ?? 'gmail';
        $back = $this->backToConnectors($company);

        if ($request->query('error')) {
            return redirect($back)->with('success', 'Връзката с Google е отказана: '.$request->query('error'));
        }

        try {
            $user = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return redirect($back)->with('success', 'Google OAuth грешка: '.$e->getMessage());
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

        return redirect($back)->with('success', ucfirst(str_replace('google_', 'Google ', $type))." свързан: {$email}");
    }

    private function googleGrantedScopes(object $user, string $service): array
    {
        $scope = $user->accessTokenResponseBody['scope'] ?? '';

        return $scope !== '' ? explode(' ', $scope) : (self::GOOGLE_SERVICES[$service]['scopes'] ?? []);
    }

    // ─────────────────────────── Slack ───────────────────────────

    public function slackRedirect(Company $company): RedirectResponse
    {
        $url = 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => config('services.slack.oauth.client_id'),
            'scope' => implode(',', self::SLACK_SCOPES),
            'redirect_uri' => config('services.slack.oauth.redirect'),
            'state' => $this->encodeState($company->id, 'slack'),
        ]);

        return redirect()->away($url);
    }

    public function slackCallback(Request $request): RedirectResponse
    {
        $state = $this->decodeState((string) $request->query('state', ''));
        if ($state === null) {
            return redirect()->route('companies.index')->with('success', 'OAuth връзката изтече или е невалидна — опитай пак.');
        }

        $company = Company::find($state['c']);
        if (! $company) {
            return redirect()->route('companies.index')->with('success', 'Фирмата не е намерена.');
        }
        $back = $this->backToConnectors($company);

        if ($request->query('error')) {
            return redirect($back)->with('success', 'Връзката със Slack е отказана: '.$request->query('error'));
        }

        $res = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
            'client_id' => config('services.slack.oauth.client_id'),
            'client_secret' => config('services.slack.oauth.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => config('services.slack.oauth.redirect'),
        ]);

        if (! $res->json('ok', false)) {
            return redirect($back)->with('success', 'Slack грешка: '.$res->json('error', 'unknown'));
        }

        $team = (string) $res->json('team.name', 'Slack');

        $company->connectors()->updateOrCreate(
            ['connector_type' => 'slack', 'display_name' => $team],
            [
                'auth_type' => 'oauth2',
                'credentials' => ['access_token' => $res->json('access_token')], // bot token, не изтича
                'scopes' => explode(',', (string) $res->json('scope', implode(',', self::SLACK_SCOPES))),
                'status' => 'active',
                'last_error' => null,
            ],
        );

        return redirect($back)->with('success', "Slack свързан: {$team}");
    }

    // ─────────────────────────── helpers ───────────────────────────

    /** Криптиран state (tamper-proof + изтичане), пътува през доставчика. */
    private function encodeState(int $companyId, string $service): string
    {
        return Crypt::encryptString(json_encode([
            'c' => $companyId,
            's' => $service,
            'e' => now()->addMinutes(15)->timestamp,
        ]));
    }

    /** @return array{c:int,s:string}|null */
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

        return ['c' => (int) $data['c'], 's' => (string) ($data['s'] ?? 'gmail')];
    }

    /**
     * Релативен URL към connectors страницата — остава на ХОСТА на callback-а
     * (flowai.local.com), за да оцелее flash съобщението (същата сесия/домейн),
     * вместо route() който сочи към APP_URL (друг домейн).
     */
    private function backToConnectors(Company $company): string
    {
        return route('companies.connectors.index', $company, absolute: false);
    }
}
