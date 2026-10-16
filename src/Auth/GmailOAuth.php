<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Auth;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorGmail\Http\GmailApiException;
use Padosoft\AskMyDocsConnectorGmail\Http\GoogleHttp;
use Padosoft\AskMyDocsConnectorGmail\Http\RequestBudget;

final class GmailOAuth
{
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private readonly GoogleIdTokenVerifier $verifier;

    private readonly GoogleHttp $http;

    public function __construct(private readonly OAuthCredentialVault $vault, private readonly TenantContext $tenantContext, ?GoogleIdTokenVerifier $verifier = null, ?GoogleHttp $http = null)
    {
        $this->http = $http ?? new GoogleHttp;
        $this->verifier = $verifier ?? new GoogleIdTokenVerifier($this->http);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return [self::READONLY_SCOPE, 'openid'];
    }

    public function authorizationUrl(int $installationId): string
    {
        $installation = $this->installation($installationId);
        $state = $this->random();
        $verifier = $this->random(64);
        $nonce = $this->random();
        $ttl = max(60, min(600, (int) config('connector-gmail.oauth.state_ttl_seconds', 600)));
        Cache::put($this->stateKey($installationId, $state), [
            'tenant_id' => $installation->tenant_id,
            'installation_id' => $installationId,
            'verifier' => $verifier,
            'nonce' => $nonce,
            'expires_at' => now()->getTimestamp() + $ttl,
        ], $ttl);
        $params = [
            'client_id' => $this->client('client_id'),
            'redirect_uri' => $this->client('redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'include_granted_scopes' => 'false',
        ];
        if ($this->vault->getRefreshToken($installationId) === null) {
            $params['prompt'] = 'consent';
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string,mixed> $query */
    public function callback(int $installationId, array $query): void
    {
        $this->installation($installationId);
        $state = $query['state'] ?? null;
        if (! is_string($state) || ! preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $state)) {
            throw new ConnectorAuthException('Missing or invalid Gmail OAuth state token.');
        }
        $entry = Cache::lock($this->stateKey($installationId, $state).':consume', 10)->block(5, fn () => Cache::pull($this->stateKey($installationId, $state)));
        if (! is_array($entry) || ($entry['tenant_id'] ?? null) !== $this->tenantContext->current()
            || ($entry['installation_id'] ?? null) !== $installationId || ($entry['expires_at'] ?? 0) <= now()->getTimestamp()
            || ! is_string($entry['nonce'] ?? null) || ! is_string($entry['verifier'] ?? null)) {
            throw new ConnectorAuthException('Expired, repeated or mismatched Gmail OAuth state token.');
        }
        if (isset($query['error']) || ! is_string($query['code'] ?? null) || $query['code'] === '') {
            throw new ConnectorAuthException('Google OAuth consent failed or no authorization code was supplied.');
        }
        $result = $this->http->token([
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'client_id' => $this->client('client_id'),
            'client_secret' => $this->client('client_secret'),
            'redirect_uri' => $this->client('redirect_uri'),
            'code_verifier' => $entry['verifier'],
        ]);
        $this->validateToken($result);
        $scopes = $this->validateScopes($result['scope'] ?? null);
        if (! is_string($result['id_token'] ?? null)) {
            throw new ConnectorAuthException('Google did not provide an identity token.');
        }
        $claims = $this->verifier->verify($result['id_token'], $this->client('client_id'), $entry['nonce']);
        $this->locked($installationId, function () use ($installationId, $result, $scopes, $claims): void {
            DB::transaction(function () use ($installationId, $result, $scopes, $claims): void {
                $this->lockCredential($installationId);
                $extra = $this->vault->getExtra($installationId);
                $oldMailbox = $extra['gmail_mailbox_id'] ?? null;
                $sameMailbox = $oldMailbox === $claims['sub'];
                $refresh = $result['refresh_token'] ?? ($sameMailbox ? $this->vault->getRefreshToken($installationId) : null);
                if (! is_string($refresh) || $refresh === '') {
                    throw new ConnectorAuthException('Google did not provide an offline refresh token; reconnect with consent.');
                }
                if ($oldMailbox !== null && ! $sameMailbox) {
                    unset($extra['gmail_sync_state'], $extra['gmail_labels']);
                }
                $extra['gmail_mailbox_id'] = $claims['sub'];
                $extra['gmail_granted_scopes'] = $scopes;
                $this->vault->setCredentials($installationId, $result['access_token'], $refresh, $this->expiresAt($result), $extra);
                $this->installation($installationId)->update(['status' => ConnectorInstallation::STATUS_ACTIVE, 'error_json' => null]);
            });
        });
    }

    public function accessToken(int $installationId, ?RequestBudget $budget = null): string
    {
        $this->installation($installationId);
        $token = $this->vault->getAccessToken($installationId);
        if ($token !== null) {
            return $token;
        }

        $budget ??= new RequestBudget(maxRequests: 6, maxBytes: 2097152, timeBudgetSeconds: 60);

        return $this->locked($installationId, function () use ($installationId, $budget): string {
            $token = $this->vault->getAccessToken($installationId);
            if ($token !== null) {
                return $token;
            }
            $refresh = $this->vault->getRefreshToken($installationId);
            if ($refresh === null) {
                throw new ConnectorAuthException('Gmail requires an offline OAuth connection.');
            }
            $result = $this->http->token([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id' => $this->client('client_id'),
                'client_secret' => $this->client('client_secret'),
            ], $budget);
            $this->validateToken($result);

            return DB::transaction(function () use ($installationId, $result, $refresh, $budget): string {
                if (! $this->lockCredential($installationId)) {
                    throw new ConnectorAuthException('Gmail was disconnected while renewing credentials.');
                }
                $extra = $this->vault->getExtra($installationId);
                $scope = $result['scope'] ?? implode(' ', is_array($extra['gmail_granted_scopes'] ?? null) ? $extra['gmail_granted_scopes'] : []);
                $extra['gmail_granted_scopes'] = $this->validateScopes($scope);
                if (isset($result['id_token'])) {
                    if (! is_string($result['id_token'])) {
                        throw new ConnectorAuthException('Google refresh identity token is invalid.');
                    }
                    $claims = $this->verifier->verify($result['id_token'], $this->client('client_id'), budget: $budget);
                    if (($extra['gmail_mailbox_id'] ?? null) !== $claims['sub']) {
                        throw new ConnectorAuthException('Google mailbox identity changed; reconnect this installation.');
                    }
                }
                $this->vault->setCredentials($installationId, $result['access_token'], $result['refresh_token'] ?? $refresh, $this->expiresAt($result), $extra);

                return $result['access_token'];
            });
        });
    }

    public function mailboxId(int $installationId): string
    {
        $this->installation($installationId);
        $id = $this->vault->getExtraKey($installationId, 'gmail_mailbox_id');
        if (! is_string($id) || $id === '') {
            throw new ConnectorAuthException('Gmail mailbox identity is missing; reconnect the installation.');
        }

        return $id;
    }

    public function disconnect(int $installationId): void
    {
        $this->installation($installationId);
        $this->locked($installationId, function () use ($installationId): void {
            $this->vault->clearCredentials($installationId);
            $this->installation($installationId)->update(['status' => ConnectorInstallation::STATUS_PENDING]);
        });
    }

    private function installation(int $installationId): ConnectorInstallation
    {
        $installation = ConnectorInstallation::query()->whereKey($installationId)
            ->where('tenant_id', $this->tenantContext->current())->where('connector_name', 'gmail')->first();
        if ($installation === null) {
            throw new ConnectorAuthException('Gmail installation is unavailable in the current tenant.');
        }

        return $installation;
    }

    /** Only acquire the database lock here. All credential reads/writes still go through the vault. */
    private function lockCredential(int $installationId): bool
    {
        $row = $this->vault->getCredentialRow($installationId);
        if ($row === null) {
            return false;
        }

        return DB::table($row->getTable())->where($row->getKeyName(), $row->getKey())
            ->where('tenant_id', $this->tenantContext->current())->lockForUpdate()->first() !== null;
    }

    /** @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(int $installationId, callable $callback): mixed
    {
        try {
            return Cache::lock('connector:gmail:oauth:'.hash('sha256', $this->tenantContext->current()).':'.$installationId, 90)->block(5, $callback);
        } catch (LockTimeoutException $exception) {
            throw new GmailApiException(0, 'oauth_refresh_busy', $exception);
        }
    }

    /** @param array<string,mixed> $result */
    private function validateToken(array $result): void
    {
        if (! is_string($result['access_token'] ?? null) || $result['access_token'] === ''
            || strtolower((string) ($result['token_type'] ?? '')) !== 'bearer'
            || ! is_int($result['expires_in'] ?? null) || $result['expires_in'] <= 0
            || (isset($result['refresh_token']) && (! is_string($result['refresh_token']) || $result['refresh_token'] === ''))) {
            throw new ConnectorAuthException('Google OAuth token response is incomplete or invalid.');
        }
    }

    /** @return list<string> */
    private function validateScopes(mixed $scope): array
    {
        $scopes = is_string($scope) ? array_values(array_unique(preg_split('/\s+/', trim($scope)) ?: [])) : [];
        foreach ($this->scopes() as $required) {
            if (! in_array($required, $scopes, true)) {
                throw new ConnectorAuthException('Google did not grant Gmail read-only access and verified identity.');
            }
        }
        foreach ($scopes as $granted) {
            if (str_starts_with($granted, 'https://www.googleapis.com/auth/gmail.') && $granted !== self::READONLY_SCOPE
                || $granted === 'https://mail.google.com/') {
                throw new ConnectorAuthException('Gmail requires a read-only OAuth grant; reconnect with a dedicated client.');
            }
        }

        return $scopes;
    }

    /** @param array<string,mixed> $result */
    private function expiresAt(array $result): Carbon
    {
        return Carbon::now()->addSeconds(max(1, min(86400, (int) $result['expires_in']) - 30));
    }

    private function client(string $key): string
    {
        $value = config('connector-gmail.oauth.'.$key);
        if (! is_string($value) || $value === '') {
            throw new ConnectorAuthException('Gmail OAuth client configuration is incomplete.');
        }

        return $value;
    }

    private function stateKey(int $installationId, string $state): string
    {
        return 'connector:gmail:state:'.hash('sha256', $this->tenantContext->current().':'.$installationId.':'.$state);
    }

    private function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
