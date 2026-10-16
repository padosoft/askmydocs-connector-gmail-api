<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Http;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Psr\Http\Message\ResponseInterface;

/** Fixed official endpoints; credentials can never follow a redirect or arbitrary URL. */
final class GoogleHttp
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get(string $url, ?string $token = null, array $query = [], ?RequestBudget $budget = null): array
    {
        if ($url !== self::JWKS_URL && ! preg_match('#^https://gmail\.googleapis\.com/gmail/v1/users/me/(?:profile|labels|messages(?:/[A-Za-z0-9_-]+(?:/attachments/[A-Za-z0-9_-]+)?)?|history)$#D', $url)) {
            throw new \InvalidArgumentException('Only official Google Gmail/JWKS endpoints are allowed.');
        }
        if ($url === self::JWKS_URL && $token !== null) {
            throw new \InvalidArgumentException('Bearer credentials must not be sent to the JWKS endpoint.');
        }

        return $this->request('GET', $url, $query, $token, $budget ?? new RequestBudget);
    }

    /** @param array<string,string> $form
     * @return array<string,mixed>
     */
    public function token(array $form, ?RequestBudget $budget = null): array
    {
        return $this->request('POST', self::TOKEN_URL, $form, null, $budget ?? new RequestBudget(maxRequests: 3, maxBytes: 1048576, timeBudgetSeconds: 60));
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $data, ?string $token, RequestBudget $budget): array
    {
        $maxAttempts = max(1, min(5, (int) config('connector-gmail.http.max_attempts', 3)));
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $budget->beforeRequest();
            $responseLimit = in_array($url, [self::TOKEN_URL, self::JWKS_URL], true) ? 1048576 : (int) config('connector-gmail.http.max_response_bytes', 41943040);
            $maxBytes = min($budget->remainingBytes(), max(1, $responseLimit));
            $timeout = min(max(0.1, (float) config('connector-gmail.http.timeout_seconds', 20)), $budget->remainingSeconds());
            $request = Http::acceptJson()->withHeaders(['Accept-Encoding' => 'identity'])
                ->timeout($timeout)
                ->connectTimeout(min($timeout, max(0.1, (float) config('connector-gmail.http.connect_timeout_seconds', 5))))
                ->withoutRedirecting()
                ->withOptions([
                    'verify' => true,
                    'decode_content' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (! ctype_digit($length) || (float) $length > $maxBytes)) {
                            throw new BudgetExceededException('Gmail response exceeds the configured byte limit.');
                        }
                    },
                    'progress' => static function (float $total, float $downloaded) use ($maxBytes): void {
                        if ($downloaded > $maxBytes || $total > $maxBytes) {
                            throw new BudgetExceededException('Gmail response exceeds the configured byte limit.');
                        }
                    },
                ]);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            try {
                $response = $method === 'POST' ? $request->asForm()->post($url, $data) : $request->get($url, $data);
            } catch (ConnectionException $exception) {
                if ($attempt === $maxAttempts) {
                    throw new GmailApiException(0, 'connection_failed');
                }
                $this->pause($attempt, null, $budget);

                continue;
            } catch (\Throwable $exception) {
                for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof BudgetExceededException) {
                        throw $cause;
                    }
                }
                if ($exception instanceof TransferException) {
                    throw new GmailApiException(0, 'transport_failure');
                }
                throw $exception;
            }
            $body = $response->body();
            if (strlen($body) > $maxBytes) {
                throw new BudgetExceededException('Gmail response exceeds the configured byte limit.');
            }
            $budget->addBytes(strlen($body));
            if ($response->successful()) {
                return $this->decode($body);
            }
            // Error gateways can return HTML. Status still controls retry/auth classification.
            try {
                $decoded = $this->decode($body);
            } catch (GmailApiException) {
                $decoded = [];
            }
            $status = $response->status();
            $reason = $this->reason($decoded);
            $transient = $status === 429 || $status >= 500 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true));
            if ($transient && $attempt < $maxAttempts) {
                $this->pause($attempt, $response, $budget);

                continue;
            }
            if (($status === 401 || ($status === 403 && ! $transient)) && ! in_array($reason, ['dailyLimitExceeded', 'quotaExceeded'], true)) {
                throw new ConnectorAuthException('Google denied access; reconnect Gmail or verify its read-only permission.');
            }
            if ($url === self::TOKEN_URL && in_array($reason, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'access_denied'], true)) {
                throw new ConnectorAuthException('Google OAuth grant is invalid; reconnect the Gmail installation.');
            }
            throw new GmailApiException($status, $reason);
        }
        throw new GmailApiException(0, 'attempts_exhausted');
    }

    /** @return array<string,mixed> */
    private function decode(string $body): array
    {
        try {
            $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GmailApiException(0, 'malformed_json', $exception);
        }
        if (! is_array($result) || ! str_starts_with(ltrim($body), '{')) {
            throw new GmailApiException(0, 'invalid_json_object');
        }

        return $result;
    }

    /** @param array<string,mixed> $data */
    private function reason(array $data): string
    {
        $error = $data['error'] ?? null;
        $reason = is_string($error) ? $error : (is_array($error) ? ($error['errors'][0]['reason'] ?? $error['status'] ?? 'api_error') : 'api_error');

        return is_string($reason) && preg_match('/^[A-Za-z0-9_]{1,80}$/D', $reason) ? $reason : 'api_error';
    }

    private function pause(int $attempt, ?Response $response, RequestBudget $budget): void
    {
        $retryAfter = $response?->header('Retry-After');
        $seconds = null;
        if (is_string($retryAfter) && $retryAfter !== '') {
            $seconds = ctype_digit($retryAfter) ? (float) $retryAfter : max(0, (float) (strtotime($retryAfter) ?: time()) - time());
        }
        $seconds ??= min(2 ** ($attempt - 1), 8) + random_int(0, 250) / 1000;
        $cap = max(0, min(60, (float) config('connector-gmail.http.retry_max_delay_seconds', 30)));
        // A long Retry-After is deferred to the host; never retry sooner than requested.
        if ($seconds > $cap || $seconds >= $budget->remainingSeconds()) {
            throw new GmailApiException($response?->status() ?? 0, 'retry_deferred');
        }
        if ($seconds > 0) {
            usleep((int) round($seconds * 1e6));
        }
        $budget->checkTime();
    }
}
