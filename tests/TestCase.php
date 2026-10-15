<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorGmail\Auth\GmailOAuth;
use Padosoft\AskMyDocsConnectorGmail\GmailServiceProvider;
use Padosoft\AskMyDocsConnectorGmail\Tests\Support\FakeIngestionBridge;

abstract class TestCase extends Orchestra
{
    protected FakeIngestionBridge $bridge;

    protected function getPackageProviders($app): array
    {
        return [ConnectorServiceProvider::class, GmailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('connector-gmail.oauth', ['client_id' => 'client.apps.googleusercontent.com', 'client_secret' => 'synthetic', 'redirect_uri' => 'https://askmydocsdev.test/api/admin/connectors/gmail/oauth/callback']);
        $app['config']->set('connector-gmail.http.retry_max_delay_seconds', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../vendor/padosoft/askmydocs-connector-base/database/migrations');
        $this->artisan('migrate')->run();
        $this->bridge = new FakeIngestionBridge;
        $this->app->instance(ConnectorIngestionContract::class, $this->bridge);
        Storage::fake('kb');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->app->make(TenantContext::class)->set('tenant-a');
    }

    protected function installation(string $tenant = 'tenant-a', string $mailbox = 'subject-1', array $config = []): ConnectorInstallation
    {
        $this->app->make(TenantContext::class)->set($tenant);
        $i = ConnectorInstallation::create(['tenant_id' => $tenant, 'connector_name' => 'gmail', 'label' => 'account-'.random_int(1, 1000000), 'project_key' => 'project-a', 'config_json' => $config, 'status' => 'active']);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($i->id, 'synthetic-'.$i->id, 'refresh-'.$i->id, now()->addHour(), ['gmail_mailbox_id' => $mailbox, 'gmail_granted_scopes' => [GmailOAuth::READONLY_SCOPE, 'openid']]);

        return $i;
    }

    protected function message(string $id = 'm1', array $labels = ['INBOX', 'UNREAD'], array $parts = []): array
    {
        return ['id' => $id, 'threadId' => 'thread-1', 'historyId' => '18446744073709551615', 'internalDate' => '1791936000000', 'labelIds' => $labels, 'sizeEstimate' => 500, 'payload' => ['mimeType' => $parts === [] ? 'text/plain' : 'multipart/mixed', 'headers' => [['name' => 'Subject', 'value' => 'Synthetic request'], ['name' => 'From', 'value' => 'Sender <sender@example.test>'], ['name' => 'To', 'value' => 'secret@example.test'], ['name' => 'Message-ID', 'value' => '<synthetic@example.test>']], 'body' => $parts === [] ? ['data' => rtrim(strtr(base64_encode('Complete email body'), '+/', '-_'), '=')] : [], 'parts' => $parts]];
    }
}
