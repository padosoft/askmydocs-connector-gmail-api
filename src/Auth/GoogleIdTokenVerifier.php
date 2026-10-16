<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorGmail\Http\GoogleHttp;
use Padosoft\AskMyDocsConnectorGmail\Http\RequestBudget;

final class GoogleIdTokenVerifier
{
    public function __construct(private readonly GoogleHttp $http = new GoogleHttp) {}

    /** @return array<string,mixed> */
    public function verify(string $token, string $audience, ?string $nonce = null, ?RequestBudget $budget = null): array
    {
        if (strlen($token) > 32768 || count($segments = explode('.', $token)) !== 3) {
            throw new ConnectorAuthException('Google ID token is invalid.');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null) || $header['kid'] === '') {
                throw new \UnexpectedValueException('Unsupported token key.');
            }
            $keys = $this->keys(budget: $budget);
            if (! isset($keys[$header['kid']])) {
                $keys = $this->keys(refresh: true, budget: $budget);
            }
            if (! isset($keys[$header['kid']])) {
                throw new \UnexpectedValueException('Unknown token key.');
            }
            $claims = (array) JWT::decode($token, $keys[$header['kid']]);
        } catch (ConnectorAuthException|ConnectorApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConnectorAuthException('Google ID token signature or validity is invalid.');
        }
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        $now = now()->getTimestamp();
        if (! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ! in_array($audience, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $audience)
            || (isset($claims['azp']) && $claims['azp'] !== $audience)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || (isset($claims['iat']) && (! is_int($claims['iat']) || $claims['iat'] > $now + 60))
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
            || ($nonce !== null && (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])))
        ) {
            throw new ConnectorAuthException('Google ID token identity claims are invalid.');
        }

        return $claims;
    }

    /** @return array<string,Key> */
    private function keys(bool $refresh = false, ?RequestBudget $budget = null): array
    {
        $cacheKey = 'connector:gmail:google-jwks:v1';
        if ($refresh) {
            Cache::forget($cacheKey);
        }
        $jwks = Cache::remember($cacheKey, 3600, fn (): array => $this->http->get(GoogleHttp::JWKS_URL, budget: $budget));
        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new ConnectorAuthException('Google signing keys are invalid.');
        }
        $trusted = array_values(array_filter($jwks['keys'], static fn ($key): bool => is_array($key)
            && ($key['kty'] ?? null) === 'RSA'
            && ($key['alg'] ?? null) === 'RS256'
            && (! isset($key['use']) || $key['use'] === 'sig')
            && is_string($key['kid'] ?? null)));

        return JWK::parseKeySet(['keys' => $trusted], 'RS256');
    }
}
