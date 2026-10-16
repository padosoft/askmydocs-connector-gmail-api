<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests\Feature;

use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorGmail\Auth\GmailOAuth;
use Padosoft\AskMyDocsConnectorGmail\Http\GoogleHttp;
use Padosoft\AskMyDocsConnectorGmail\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OAuthTest extends TestCase
{
    private string $privateKey = '';

    /** @var array<string,string> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('default');
        Cache::flush();
        config()->set('connector-gmail.oauth', ['client_id' => 'gmail-client', 'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://askmydocsdev.test/gmail/callback']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->jwk = ['kid' => 'fixture-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
    }

    public function test_authorization_uses_readonly_offline_state_nonce_and_pkce(): void
    {
        $id = $this->syntheticInstallation()->id;
        $params = $this->authorization($id);
        self::assertSame('offline', $params['access_type']);
        self::assertSame('consent', $params['prompt']);
        self::assertSame(GmailOAuth::READONLY_SCOPE.' openid', $params['scope']);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertSame('false', $params['include_granted_scopes']);
        self::assertNotEmpty($params['state']);
        self::assertNotEmpty($params['nonce']);
        Http::assertNothingSent();
    }

    public function test_callback_verifies_identity_encrypts_tokens_and_consumes_state_once(): void
    {
        $id = $this->syntheticInstallation()->id;
        $params = $this->authorization($id);
        $this->tokenResponse($params['nonce']);
        $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
        self::assertSame('synthetic-access', $this->vault()->getAccessToken($id));
        self::assertSame('synthetic-refresh', $this->vault()->getRefreshToken($id));
        self::assertSame('stable-google-sub', $this->oauth()->mailboxId($id));
        self::assertNotSame('synthetic-access', $this->vault()->getCredentialRow($id)->encrypted_access_token);
        Http::assertSent(function ($request) use ($params): bool {
            if ($request->url() !== GoogleHttp::TOKEN_URL) {
                return false;
            }
            $verifier = $request['code_verifier'];

            return $request['grant_type'] === 'authorization_code'
                && $params['code_challenge'] === JWT::urlsafeB64Encode(hash('sha256', $verifier, true));
        });
        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('state token');
        $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
    }

    #[DataProvider('invalidIdentityClaims')]
    public function test_callback_rejects_untrusted_identity_claims(array $overrides): void
    {
        $id = $this->syntheticInstallation()->id;
        $params = $this->authorization($id);
        $this->tokenResponse($params['nonce'], $overrides);
        try {
            $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
            self::fail('Untrusted identity must not be connected.');
        } catch (ConnectorAuthException) {
            self::assertNull($this->vault()->getCredentialRow($id));
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidIdentityClaims(): iterable
    {
        yield 'issuer' => [['iss' => 'https://attacker.example.test']];
        yield 'audience' => [['aud' => 'other-client']];
        yield 'expired' => [['exp' => 1]];
        yield 'nonce' => [['nonce' => 'wrong-flow']];
        yield 'subject' => [['sub' => '']];
        yield 'authorized-party' => [['aud' => ['gmail-client', 'other-client'], 'azp' => 'other-client']];
    }

    public function test_callback_rejects_bad_signature(): void
    {
        $id = $this->syntheticInstallation()->id;
        $params = $this->authorization($id);
        $this->tokenResponse($params['nonce'], corruptSignature: true);
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
    }

    public function test_callback_rejects_missing_readonly_scope_before_identity_fetch(): void
    {
        $id = $this->syntheticInstallation()->id;
        $params = $this->authorization($id);
        Http::fake([GoogleHttp::TOKEN_URL => Http::response(['access_token' => 'synthetic', 'refresh_token' => 'synthetic', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'openid'])]);
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
    }

    public function test_state_is_bound_to_installation_and_tenant(): void
    {
        $first = $this->syntheticInstallation();
        $second = $this->syntheticInstallation();
        $params = $this->authorization($first->id);
        try {
            $this->oauth()->callback($second->id, ['state' => $params['state'], 'code' => 'fixture-code']);
            self::fail('Another installation cannot consume OAuth state.');
        } catch (ConnectorAuthException) {
            Http::assertNothingSent();
        }
        $this->app->make(TenantContext::class)->set('other-tenant');
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($first->id, ['state' => $params['state'], 'code' => 'fixture-code']);
    }

    public function test_expired_state_and_provider_error_do_not_exchange_tokens(): void
    {
        $id = $this->syntheticInstallation()->id;
        $params = $this->authorization($id);
        $this->travel(601)->seconds();
        try {
            $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
            self::fail('Expired state must fail.');
        } catch (ConnectorAuthException) {
            Http::assertNothingSent();
        }
        $params = $this->authorization($id);
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($id, ['state' => $params['state'], 'error' => 'access_denied']);
    }

    public function test_refresh_preserves_refresh_token_identity_and_every_extra_key(): void
    {
        $id = $this->syntheticInstallation()->id;
        $extra = ['gmail_mailbox_id' => 'stable-google-sub', 'gmail_granted_scopes' => [GmailOAuth::READONLY_SCOPE, 'openid'], 'gmail_sync_state' => ['history_id' => '99999999999999999999'], 'unrelated' => ['nested' => true]];
        $this->vault()->setCredentials($id, 'expired-access', 'old-refresh', Carbon::now()->subMinute(), $extra);
        Http::fake([GoogleHttp::TOKEN_URL => Http::response(['access_token' => 'renewed-access', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);
        self::assertSame('renewed-access', $this->oauth()->accessToken($id));
        self::assertSame('old-refresh', $this->vault()->getRefreshToken($id));
        self::assertSame($extra, $this->vault()->getExtra($id));
        self::assertSame('renewed-access', $this->oauth()->accessToken($id));
        Http::assertSentCount(1);
    }

    public function test_invalid_grant_requires_reauthentication_without_losing_state(): void
    {
        $id = $this->syntheticInstallation()->id;
        $extra = ['gmail_mailbox_id' => 'stable-google-sub', 'gmail_sync_state' => ['history_id' => '100']];
        $this->vault()->setCredentials($id, 'expired-access', 'old-refresh', Carbon::now()->subMinute(), $extra);
        Http::fake([GoogleHttp::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        try {
            $this->oauth()->accessToken($id);
            self::fail('Invalid grant must require reconnect.');
        } catch (ConnectorAuthException) {
            self::assertSame($extra, $this->vault()->getExtra($id));
            self::assertSame('old-refresh', $this->vault()->getRefreshToken($id));
        }
    }

    public function test_reconnect_same_subject_preserves_old_refresh_and_cursor_when_omitted(): void
    {
        $id = $this->syntheticInstallation()->id;
        $this->vault()->setCredentials($id, 'old-access', 'old-refresh', Carbon::now()->addHour(), ['gmail_mailbox_id' => 'stable-google-sub', 'gmail_sync_state' => ['history_id' => '100'], 'unrelated' => 7]);
        $params = $this->authorization($id);
        self::assertArrayNotHasKey('prompt', $params);
        $this->tokenResponse($params['nonce'], omitRefresh: true);
        $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
        self::assertSame('old-refresh', $this->vault()->getRefreshToken($id));
        self::assertSame(['history_id' => '100'], $this->vault()->getExtraKey($id, 'gmail_sync_state'));
        self::assertSame(7, $this->vault()->getExtraKey($id, 'unrelated'));
    }

    public function test_changed_subject_discards_old_cursor_but_preserves_unrelated_extra(): void
    {
        $id = $this->syntheticInstallation()->id;
        $this->vault()->setCredentials($id, 'old-access', 'old-refresh', Carbon::now()->addHour(), ['gmail_mailbox_id' => 'old-sub', 'gmail_sync_state' => ['history_id' => '100'], 'gmail_labels' => ['a' => 'A'], 'unrelated' => 7]);
        $params = $this->authorization($id);
        $this->tokenResponse($params['nonce']);
        $this->oauth()->callback($id, ['state' => $params['state'], 'code' => 'fixture-code']);
        self::assertSame('stable-google-sub', $this->oauth()->mailboxId($id));
        self::assertNull($this->vault()->getExtraKey($id, 'gmail_sync_state'));
        self::assertNull($this->vault()->getExtraKey($id, 'gmail_labels'));
        self::assertSame(7, $this->vault()->getExtraKey($id, 'unrelated'));
    }

    public function test_disconnect_clears_only_scoped_installation_without_remote_revoke(): void
    {
        $first = $this->syntheticInstallation();
        $second = $this->syntheticInstallation();
        foreach ([$first, $second] as $installation) {
            $this->vault()->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', Carbon::now()->addHour(), ['gmail_mailbox_id' => 'same-google-sub']);
        }
        $this->oauth()->disconnect($first->id);
        self::assertNull($this->vault()->getCredentialRow($first->id));
        self::assertSame('synthetic-access', $this->vault()->getAccessToken($second->id));
        Http::assertNothingSent();
    }

    /** @param array<string,mixed> $overrides */
    private function tokenResponse(string $nonce, array $overrides = [], bool $corruptSignature = false, bool $omitRefresh = false): void
    {
        $claims = array_replace(['iss' => 'https://accounts.google.com', 'aud' => 'gmail-client', 'exp' => time() + 3600, 'iat' => time() - 1, 'sub' => 'stable-google-sub', 'nonce' => $nonce], $overrides);
        $token = JWT::encode($claims, $this->privateKey, 'RS256', 'fixture-key');
        if ($corruptSignature) {
            $parts = explode('.', $token);
            $parts[2] = JWT::urlsafeB64Encode(random_bytes(256));
            $token = implode('.', $parts);
        }
        $result = ['access_token' => 'synthetic-access', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => GmailOAuth::READONLY_SCOPE.' openid', 'id_token' => $token];
        if (! $omitRefresh) {
            $result['refresh_token'] = 'synthetic-refresh';
        }
        Http::fake([GoogleHttp::TOKEN_URL => Http::response($result), GoogleHttp::JWKS_URL => Http::response(['keys' => [$this->jwk]])]);
    }

    private function syntheticInstallation(): ConnectorInstallation
    {
        return ConnectorInstallation::create(['tenant_id' => 'default', 'connector_name' => 'gmail', 'label' => bin2hex(random_bytes(4)), 'status' => 'pending']);
    }

    /** @return array<string,string> */
    private function authorization(int $id): array
    {
        parse_str((string) parse_url($this->oauth()->authorizationUrl($id), PHP_URL_QUERY), $query);

        return $query;
    }

    private function oauth(): GmailOAuth
    {
        return $this->app->make(GmailOAuth::class);
    }

    private function vault(): OAuthCredentialVault
    {
        return $this->app->make(OAuthCredentialVault::class);
    }
}
