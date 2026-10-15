<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorGmail\Auth\GmailOAuth;
use Padosoft\AskMyDocsConnectorGmail\Http\BudgetExceededException;
use Padosoft\AskMyDocsConnectorGmail\Http\GmailApiException;
use Padosoft\AskMyDocsConnectorGmail\Http\GmailClient;
use Padosoft\AskMyDocsConnectorGmail\Http\GoogleHttp;
use Padosoft\AskMyDocsConnectorGmail\Http\RequestBudget;
use Padosoft\AskMyDocsConnectorGmail\Tests\TestCase;

final class TransportTest extends TestCase
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('default');
    }

    public function test_client_reads_full_message_labels_attachment_profile_history_without_mutation(): void
    {
        $id = $this->syntheticInstallation('access-one');
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;

            return Http::response(['fixture' => true, 'id' => 'native-id', 'data' => 'Zml4dHVyZQ']);
        });
        $client = $this->app->make(GmailClient::class);
        $budget = new RequestBudget;
        $client->profile($id, $budget);
        $client->labels($id, $budget);
        $client->messages($id, ['maxResults' => 50, 'pageToken' => 'opaque+/='], $budget);
        $client->message($id, 'native-id', $budget);
        $client->attachment($id, 'native-id', 'native-attachment', $budget);
        $client->history($id, ['startHistoryId' => '99999999999999999999999999'], $budget);
        Http::assertSentCount(6);
        foreach (Http::recorded() as [$request]) {
            self::assertSame('GET', $request->method());
            self::assertStringStartsWith(self::BASE, $request->url());
            self::assertTrue($request->hasHeader('Authorization', 'Bearer access-one'));
        }
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'messages/native-id?format=full');
        foreach ($options as $option) {
            self::assertFalse($option['allow_redirects']);
            self::assertLessThanOrEqual(20, $option['timeout']);
            self::assertLessThanOrEqual(5, $option['connect_timeout']);
            self::assertArrayHasKey('progress', $option);
        }
        self::assertSame(6, $budget->statistics()['requests']);
    }

    public function test_client_resolves_credentials_for_each_installation(): void
    {
        $first = $this->syntheticInstallation('access-one');
        $second = $this->syntheticInstallation('access-two');
        $headers = [];
        Http::fake(function (Request $request) use (&$headers) {
            $headers[] = $request->header('Authorization')[0];

            return Http::response(['historyId' => 'opaque']);
        });
        $client = $this->app->make(GmailClient::class);
        $client->profile($first);
        $client->profile($second);
        $client->profile($first);
        self::assertSame(['Bearer access-one', 'Bearer access-two', 'Bearer access-one'], $headers);
    }

    public function test_retries_429_and_5xx_with_retry_after_within_budget(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::sequence()
            ->push(['error' => ['errors' => [['reason' => 'rateLimitExceeded']]]], 429, ['Retry-After' => '0'])
            ->push(['error' => ['status' => 'UNAVAILABLE']], 503, ['Retry-After' => '0'])
            ->push(['historyId' => 'opaque'], 200)]);
        $budget = new RequestBudget;
        self::assertSame(['historyId' => 'opaque'], $this->app->make(GmailClient::class)->profile($id, $budget));
        self::assertSame(3, $budget->statistics()['requests']);
        Http::assertSentCount(3);
    }

    public function test_quota_403_is_retried_but_permission_403_requires_reconnect(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::sequence()
            ->push(['error' => ['errors' => [['reason' => 'userRateLimitExceeded']]]], 403, ['Retry-After' => '0'])
            ->push(['error' => ['errors' => [['reason' => 'insufficientPermissions']]]], 403)]);
        $this->expectException(ConnectorAuthException::class);
        $this->app->make(GmailClient::class)->profile($id);
    }

    public function test_long_retry_after_defers_instead_of_retrying_early(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429, ['Retry-After' => '120'])]);
        try {
            $this->app->make(GmailClient::class)->profile($id);
            self::fail('Long Retry-After must defer.');
        } catch (GmailApiException $exception) {
            self::assertSame('retry_deferred', $exception->reason);
            Http::assertSentCount(1);
        }
    }

    public function test_redirect_is_not_followed_or_given_bearer(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::response('{}', 302, ['Location' => 'https://attacker.example.test'])]);
        try {
            $this->app->make(GmailClient::class)->profile($id);
            self::fail('Redirect must fail.');
        } catch (GmailApiException $exception) {
            self::assertSame(302, $exception->status);
            Http::assertSentCount(1);
        }
    }

    public function test_external_endpoint_and_path_injection_are_rejected_before_http(): void
    {
        try {
            (new GoogleHttp)->get('https://attacker.example.test/gmail/v1/users/me/profile', 'synthetic-access');
            self::fail('Foreign endpoint must fail.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
        $id = $this->syntheticInstallation();
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(GmailClient::class)->message($id, '../other-account');
    }

    public function test_json_and_payload_limits_fail_explicitly(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::sequence()->push('broken-json')->push(['large' => str_repeat('a', 100)])]);
        try {
            $this->app->make(GmailClient::class)->profile($id);
            self::fail('Malformed JSON must fail.');
        } catch (GmailApiException $exception) {
            self::assertSame('malformed_json', $exception->reason);
        }
        config()->set('connector-gmail.http.max_response_bytes', 50);
        $this->expectException(BudgetExceededException::class);
        $this->app->make(GmailClient::class)->profile($id);
    }

    public function test_request_budget_stops_before_next_request(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::response(['historyId' => 'opaque'])]);
        $budget = new RequestBudget(maxRequests: 1);
        $client = $this->app->make(GmailClient::class);
        $client->profile($id, $budget);
        try {
            $client->profile($id, $budget);
            self::fail('Second request exceeds budget.');
        } catch (BudgetExceededException) {
            Http::assertSentCount(1);
        }
    }

    public function test_byte_budget_does_not_accept_oversized_response(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::response(['historyId' => 'opaque'])]);
        $this->expectException(BudgetExceededException::class);
        $this->app->make(GmailClient::class)->profile($id, new RequestBudget(maxBytes: 5));
    }

    public function test_http_404_retains_status_for_history_vs_message_recovery(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'history*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404)]);
        try {
            $this->app->make(GmailClient::class)->history($id, ['startHistoryId' => 'opaque']);
            self::fail('Expired history must retain 404.');
        } catch (GmailApiException $exception) {
            self::assertSame(404, $exception->status);
            self::assertSame('NOT_FOUND', $exception->reason);
        }
    }

    private function syntheticInstallation(string $access = 'synthetic-access'): int
    {
        $installation = ConnectorInstallation::create(['tenant_id' => 'default', 'connector_name' => 'gmail', 'label' => bin2hex(random_bytes(4)), 'status' => 'active']);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($installation->id, $access, 'synthetic-refresh', Carbon::now()->addHour(), ['gmail_mailbox_id' => 'verified-sub']);

        return $installation->id;
    }

    public function test_oauth_refresh_shares_the_callers_request_and_time_budget(): void
    {
        $id = $this->syntheticInstallation();
        $this->app->make(OAuthCredentialVault::class)->setCredentials($id, 'expired', 'synthetic-refresh', Carbon::now()->subHour(), ['gmail_mailbox_id' => 'verified-sub', 'gmail_granted_scopes' => [GmailOAuth::READONLY_SCOPE, 'openid']]);
        $options = [];
        Http::fake(function ($request, $requestOptions) use (&$options) {
            $options[] = $requestOptions;

            return $request->url() === GoogleHttp::TOKEN_URL
                ? Http::response(['access_token' => 'renewed', 'expires_in' => 3600, 'token_type' => 'Bearer'])
                : Http::response(['historyId' => '100']);
        });
        $budget = new RequestBudget(maxRequests: 2, timeBudgetSeconds: 5);
        $this->app->make(GmailClient::class)->profile($id, $budget);
        self::assertSame(2, $budget->statistics()['requests']);
        foreach ($options as $option) {
            self::assertLessThanOrEqual(5, $option['timeout']);
            self::assertTrue($option['verify']);
        }
    }

    public function test_html_gateway_failure_retries_from_status_and_does_not_expose_response(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'profile' => Http::sequence()->push('<html>upstream unavailable</html>', 503, ['Retry-After' => '0'])->push(['historyId' => '100'])]);
        self::assertSame(['historyId' => '100'], $this->app->make(GmailClient::class)->profile($id));
        Http::assertSentCount(2);
    }

    public function test_mismatched_native_message_and_missing_attachment_payload_fail(): void
    {
        $id = $this->syntheticInstallation();
        Http::fake([self::BASE.'messages/native-id*' => Http::response(['id' => 'other-message'])]);
        try {
            $this->app->make(GmailClient::class)->message($id, 'native-id');
            self::fail('A different native identity cannot be ingested.');
        } catch (GmailApiException $exception) {
            self::assertSame('mismatched_message_identity', $exception->reason);
        }
        $this->expectException(GmailApiException::class);
        $this->expectExceptionMessage('missing_attachment_data');
        $this->app->make(GmailClient::class)->attachment($id, 'native-id', 'file-id');
    }
}
