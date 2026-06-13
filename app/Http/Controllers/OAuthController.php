<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * OAuth flow за MCP конекторите — глобален FlowAI app (Google/Slack). Записва
 * encrypted токени в company_connectors. Google refresh се прави автоматично от
 * McpClientService; Slack bot токените не изтичат.
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
        $service = $request->query('service', 'gmail');
        $cfg = self::GOOGLE_SERVICES[$service] ?? self::GOOGLE_SERVICES['gmail'];

        session(['mcp_oauth_company' => $company->id, 'mcp_oauth_service' => $service]);

        return Socialite::driver('google')
            ->scopes([...$cfg['scopes'], 'openid', 'email', 'profile'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function googleCallback(): RedirectResponse
    {
        $company = Company::findOrFail((int) session('mcp_oauth_company'));
        $service = (string) session('mcp_oauth_service', 'gmail');
        $type = self::GOOGLE_SERVICES[$service]['type'] ?? 'gmail';

        $user = Socialite::driver('google')->user();
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

        return redirect()
            ->route('companies.connectors.index', $company)
            ->with('success', ucfirst(str_replace('google_', 'Google ', $type))." свързан: {$email}");
    }

    private function googleGrantedScopes(object $user, string $service): array
    {
        $scope = $user->accessTokenResponseBody['scope'] ?? '';

        return $scope !== '' ? explode(' ', $scope) : (self::GOOGLE_SERVICES[$service]['scopes'] ?? []);
    }

    // ─────────────────────────── Slack ───────────────────────────

    public function slackRedirect(Company $company): RedirectResponse
    {
        $state = Str::random(40);
        session(['mcp_oauth_company' => $company->id, 'mcp_slack_state' => $state]);

        $url = 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => config('services.slack.oauth.client_id'),
            'scope' => implode(',', self::SLACK_SCOPES),
            'redirect_uri' => config('services.slack.oauth.redirect'),
            'state' => $state,
        ]);

        return redirect()->away($url);
    }

    public function slackCallback(Request $request): RedirectResponse
    {
        $company = Company::findOrFail((int) session('mcp_oauth_company'));

        abort_unless($request->query('state') === session('mcp_slack_state'), 403, 'Невалиден OAuth state');

        $res = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
            'client_id' => config('services.slack.oauth.client_id'),
            'client_secret' => config('services.slack.oauth.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => config('services.slack.oauth.redirect'),
        ]);

        if (! $res->json('ok', false)) {
            return redirect()->route('companies.connectors.index', $company)
                ->with('success', 'Slack грешка: '.$res->json('error', 'unknown'));
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

        return redirect()
            ->route('companies.connectors.index', $company)
            ->with('success', "Slack свързан: {$team}");
    }
}
