<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpConnectorInterface;
use Illuminate\Support\Facades\Http;

abstract class AbstractConnector implements McpConnectorInterface
{
    /**
     * Инжектираните credentials + мета (auth_type, scopes), сложени от
     * McpClientService::resolve(). НИКОГА не се логва/сериализира.
     *
     * @var array<string,mixed>
     */
    protected array $credentials = [];

    public function withCredentials(array $credentials): static
    {
        $clone = clone $this;
        $clone->credentials = $credentials;

        return $clone;
    }

    /** Default: конекторът няма live опции. Override-ва се per source. */
    public function listOptions(string $source, array $context = []): array
    {
        return [];
    }

    /** @return array{name:string,description:string,parameters:array,writes:bool}|null */
    protected function toolDef(string $tool): ?array
    {
        foreach ($this->listTools() as $def) {
            if (($def['name'] ?? null) === $tool) {
                return $def;
            }
        }

        return null;
    }

    /** Гранатираните OAuth scopes (от company_connectors.scopes). */
    protected function grantedScopes(): array
    {
        return array_map('strval', (array) ($this->credentials['scopes'] ?? []));
    }

    /** Има ли конекторът някой от подадените OAuth scopes (case-insensitive). */
    protected function hasScope(string ...$scopes): bool
    {
        $granted = array_map('strtolower', $this->grantedScopes());
        foreach ($scopes as $scope) {
            if (in_array(strtolower($scope), $granted, true)) {
                return true;
            }
        }

        return false;
    }

    /** Валидира Google access token през tokeninfo (за testConnection). */
    protected function googleTokenValid(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }
        try {
            return Http::timeout(10)->get('https://www.googleapis.com/oauth2/v1/tokeninfo', ['access_token' => $token])->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
