<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorGmail\GmailConnector;
use Padosoft\AskMyDocsConnectorGmail\Tests\TestCase;

final class SyncTest extends TestCase
{
    private function fake(array $messages, array $history = [], ?string $next = null, string $projectLabelName = 'Projects'): void
    {
        $this->freshHttp();
        Http::fake(function ($request) use ($messages, $history, $next, $projectLabelName) {
            $url = $request->url();
            if (str_contains($url, '/profile')) {
                return Http::response(['emailAddress' => 'display@example.test', 'historyId' => '100']);
            }
            if (str_contains($url, '/labels')) {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox'], ['id' => 'UNREAD', 'name' => 'Unread'], ['id' => 'STARRED', 'name' => 'Starred'], ['id' => 'Label_1', 'name' => $projectLabelName], ['id' => 'Label_2', 'name' => 'Archive'], ['id' => 'SPAM', 'name' => 'Spam'], ['id' => 'TRASH', 'name' => 'Trash']]]);
            }
            if (str_contains($url, '/history')) {
                return Http::response(['historyId' => '18446744073709551615', 'history' => $history]);
            }
            if (preg_match('~/messages/([^/?]+)~', $url, $match)) {
                return isset($messages[$match[1]]) ? Http::response($messages[$match[1]]) : Http::response([], 404);
            }

            return Http::response(array_filter(['messages' => array_map(fn ($id) => ['id' => $id], array_keys($messages)), 'nextPageToken' => $next], fn ($v) => $v !== null));
        });
    }

    public function test_full_sync_prepares_before_write_with_redacted_hash_and_no_mutation(): void
    {
        $i = $this->installation();
        $this->fake(['m1' => $this->message()]);
        $prepared = [];
        Event::listen('askmydocs.email.prepared.v1', function (array $e) use (&$prepared) {
            $this->assertSame([], Storage::disk('kb')->allFiles());
            $this->assertSame([], $this->bridge->documents);
            $prepared[] = $e;
        });
        $result = $this->app->make(GmailConnector::class)->syncFull($i->id);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(1, $result->documentsAdded);
        $this->assertCount(1, $prepared);
        $doc = $this->bridge->documents[0];
        $body = Storage::disk('kb')->get($doc['relativePath']);
        $this->assertStringNotContainsString('secret@example.test', $body);
        $this->assertSame(hash('sha256', $body), $doc['metadata']['email_body_hash']);
        $this->assertSame('text/markdown', $doc['mimeType']);
        $this->assertSame('18446744073709551615', $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['committed']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'format=full'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_listener_failure_leaves_checkpoint_uncommitted_and_writes_nothing(): void
    {
        $i = $this->installation();
        $this->fake(['m1' => $this->message()]);
        Event::listen('askmydocs.email.prepared.v1', fn () => throw new \RuntimeException('Synthetic listener failure'));
        try {
            $this->app->make(GmailConnector::class)->syncFull($i->id);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic listener failure', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('kb')->allFiles());
        $state = $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state');
        $this->assertNull($state['committed']);
        $this->assertSame(0, $state['pending']['offset']);
    }

    public function test_dispatch_crash_resumes_without_skipping_or_advancing_cursor(): void
    {
        $i = $this->installation();
        $this->fake(['m1' => $this->message(), 'm2' => $this->message('m2')]);
        $this->bridge->failAt = 1;
        $connector = $this->app->make(GmailConnector::class);
        try {
            $connector->syncFull($i->id);
            $this->fail();
        } catch (\RuntimeException) {
        }
        $vault = $this->app->make(OAuthCredentialVault::class);
        $state = $vault->getExtraKey($i->id, 'gmail_sync_state');
        $this->assertNull($state['committed']);
        $this->assertSame(1, $state['pending']['offset']);
        $result = $connector->syncIncremental($i->id, null);
        $this->assertFalse($result->hasErrors());
        $this->assertCount(2, $this->bridge->documents);
        $this->assertNotNull($vault->getExtraKey($i->id, 'gmail_sync_state')['committed']);
    }

    public function test_multilabel_or_and_account_identity_are_isolated(): void
    {
        $i = $this->installation(config: ['folders' => ['include' => ['Label_1', 'Label_2']]]);
        $j = $this->installation(mailbox: 'subject-1', config: ['folders' => ['include' => ['Label_1', 'Label_2']]]);
        $this->fake(['m1' => $this->message(labels: ['Label_1', 'Label_2'])]);
        $c = $this->app->make(GmailConnector::class);
        $c->syncFull($i->id);
        $c->syncFull($j->id);
        $this->assertCount(2, $this->bridge->documents);
        $a = $this->bridge->documents[0];
        $b = $this->bridge->documents[1];
        $this->assertNotSame($a['metadata']['email_message_key'], $b['metadata']['email_message_key']);
        $this->assertNotSame($a['relativePath'], $b['relativePath']);
        Http::assertNotSent(fn ($r) => isset($r['labelIds']));
    }

    public function test_incremental_label_changes_reuse_body_revision_and_deletions_use_composite_key(): void
    {
        $i = $this->installation(config: ['reconcile_deletions' => true]);
        $c = $this->app->make(GmailConnector::class);
        $this->fake(['m1' => $this->message()]);
        $c->syncFull($i->id);
        $key = $this->bridge->documents[0]['metadata']['email_message_key'];
        $revision = $this->bridge->documents[0]['metadata']['email_revision_hash'];
        $this->fake(['m1' => $this->message(labels: ['INBOX', 'STARRED'])], [['labelsRemoved' => [['message' => ['id' => 'm1'], 'labelIds' => ['UNREAD']]]]]);
        $c->syncIncremental($i->id, null);
        $this->assertCount(2, $this->bridge->documents);
        $this->assertSame($revision, $this->bridge->documents[1]['metadata']['email_revision_hash']);
        $this->assertSame($key, $this->bridge->documents[1]['metadata']['email_message_key']);
        $this->fake([], [['messagesDeleted' => [['message' => ['id' => 'm1']]]]]);
        $c->syncIncremental($i->id, null);
        $this->assertSame($key, $this->bridge->deleted[0]['id']);
        $this->assertSame($i->id, $this->bridge->deleted[0]['installation']);
    }

    public function test_full_resume_batches_and_history_anchor_catch_concurrent_arrival(): void
    {
        $i = $this->installation();
        config(['connector-gmail.sync.max_messages' => 1]);
        $this->fake(['m1' => $this->message()], [['messagesAdded' => [['message' => ['id' => 'm2']]]]]);
        $c = $this->app->make(GmailConnector::class);
        $first = $c->syncFull($i->id);
        $this->assertTrue($first->hasErrors());
        $this->fake(['m1' => $this->message(), 'm2' => $this->message('m2')], [['messagesAdded' => [['message' => ['id' => 'm2']]]]]);
        config(['connector-gmail.sync.max_messages' => 5]);
        $c->syncIncremental($i->id, null);
        $this->assertCount(2, $this->bridge->documents);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'startHistoryId=100'));
    }

    public function test_expired_history_recovers_full_and_incomplete_scan_never_prunes(): void
    {
        $i = $this->installation(config: ['reconcile_deletions' => true]);
        $c = $this->app->make(GmailConnector::class);
        $this->fake(['m1' => $this->message()]);
        $c->syncFull($i->id);
        $expired = true;
        $this->freshHttp();
        Http::fake(function ($r) use (&$expired) {
            $u = $r->url();
            if (str_contains($u, '/labels')) {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox']]]);
            }if (str_contains($u, '/profile')) {
                return Http::response(['historyId' => '200']);
            }if (str_contains($u, '/history')) {
                if ($expired) {
                    $expired = false;

                    return Http::response([], 404);
                }

                return Http::response(['historyId' => '300']);
            }

            return Http::response(['messages' => []]);
        });
        config(['connector-gmail.sync.max_pages' => 1]);
        $c->syncIncremental($i->id, null);
        $this->assertSame([], $this->bridge->deleted);
        config(['connector-gmail.sync.max_pages' => 10]);
        $c->syncIncremental($i->id, null);
        $this->assertCount(1, $this->bridge->deleted);
    }

    public function test_same_name_attachment_manifest_slots_order_and_replay(): void
    {
        $i = $this->installation();
        $parts = [['partId' => 'body', 'mimeType' => 'text/plain', 'body' => ['data' => 'Ym9keQ']], ['partId' => 'b', 'filename' => '../report.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 3, 'data' => 'dHdv']], ['partId' => 'a', 'filename' => '../report.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 3, 'data' => 'b25l']], ['partId' => 'c', 'filename' => 'image.png', 'mimeType' => 'image/png', 'headers' => [['name' => 'Content-ID', 'value' => '<inline>']], 'body' => ['size' => 2, 'data' => 'YWI']], ['partId' => 'd', 'filename' => 'unsupported.exe', 'mimeType' => 'application/octet-stream', 'body' => ['size' => 1, 'data' => 'YQ']]];
        $this->fake(['m1' => $this->message(parts: $parts)]);
        $events = [];
        Event::listen('askmydocs.email.prepared.v1', function ($e) use (&$events) {
            $events[] = $e;
        });
        $c = $this->app->make(GmailConnector::class);
        $c->syncFull($i->id);
        $this->assertCount(3, $this->bridge->documents);
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($events[0]['manifest'], 'id'));
        $this->assertSame(['pending', 'pending', 'excluded', 'excluded'], array_column($events[0]['manifest'], 'status'));
        $a = $this->bridge->documents[1];
        $b = $this->bridge->documents[2];
        $this->assertNotSame($a['relativePath'], $b['relativePath']);
        $this->assertSame($a['metadata']['email_message_key'], $b['metadata']['email_message_key']);
        $c->syncFull($i->id);
        $this->assertCount(3, $this->bridge->documents);
        $this->assertCount(1, $events);
    }

    public function test_health_and_discovery_do_not_change_sync_state_or_ingest(): void
    {
        $i = $this->installation();
        $this->fake([]);
        $vault = $this->app->make(OAuthCredentialVault::class);
        $vault->setExtraKey($i->id, 'gmail_sync_state', ['synthetic' => 'unchanged']);
        $before = $vault->getExtra($i->id);
        $c = $this->app->make(GmailConnector::class);
        $this->assertSame('healthy', $c->health($i->id)->state);
        $this->assertContains('INBOX', $c->listAvailableFolders($i->id));
        $this->assertSame($before, $vault->getExtra($i->id));
        $this->assertSame([], $this->bridge->documents);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages'));
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_attachment_dispatch_crash_replays_only_failed_slots(): void
    {
        $i = $this->installation();
        $parts = [
            ['partId' => '0', 'mimeType' => 'text/plain', 'body' => ['data' => 'Ym9keQ']],
            ['partId' => 'a', 'filename' => 'first.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 3, 'data' => 'b25l']],
            ['partId' => 'b', 'filename' => 'second.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 3, 'data' => 'dHdv']],
        ];
        $this->fake(['m1' => $this->message(parts: $parts)]);
        $events = [];
        Event::listen('askmydocs.email.prepared.v1', function (array $envelope) use (&$events): void {
            $events[] = $envelope;
        });
        $this->bridge->failAt = 2;
        $connector = $this->app->make(GmailConnector::class);
        try {
            $connector->syncFull($i->id);
            self::fail('Second attachment dispatch must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic dispatch failure', $exception->getMessage());
        }
        self::assertCount(2, $this->bridge->documents);
        self::assertNull($this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['committed']);
        $result = $connector->syncIncremental($i->id, null);
        self::assertFalse($result->hasErrors());
        self::assertSame(1, $result->documentsAdded);
        self::assertCount(3, $this->bridge->documents);
        self::assertSame(['body.md', '0-'.hash('sha256', 'a').'-first.txt', '1-'.hash('sha256', 'b').'-second.txt'], array_map(fn (array $document): string => basename($document['relativePath']), $this->bridge->documents));
        self::assertCount(2, $events);
        self::assertSame($events[0], $events[1]);
        $connector->syncFull($i->id);
        self::assertCount(3, $this->bridge->documents);
        self::assertCount(2, $events);
    }

    public function test_full_and_catchup_process_all_pages_before_committing_history(): void
    {
        $i = $this->installation(config: ['reconcile_deletions' => true]);
        $this->freshHttp();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/labels')) {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox']]]);
            }
            if (str_contains($url, '/profile')) {
                return Http::response(['historyId' => '100']);
            }
            if (str_contains($url, '/history')) {
                return isset($request['pageToken'])
                    ? Http::response(['historyId' => '200', 'history' => [['labelsAdded' => [['message' => ['id' => 'm1']]]]]])
                    : Http::response(['historyId' => '150', 'nextPageToken' => 'history-page-two', 'history' => [['messagesAdded' => [['message' => ['id' => 'm3']]]]]]);
            }
            if (preg_match('~/messages/([^/?]+)~', $url, $matches)) {
                return Http::response($this->message($matches[1], ['INBOX']));
            }

            return isset($request['pageToken'])
                ? Http::response(['messages' => [['id' => 'm2']]])
                : Http::response(['messages' => [['id' => 'm1']], 'nextPageToken' => 'full-page-two']);
        });
        $result = $this->app->make(GmailConnector::class)->syncFull($i->id);
        self::assertFalse($result->hasErrors());
        self::assertCount(3, $this->bridge->documents);
        self::assertSame('200', $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['committed']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'pageToken=full-page-two'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'pageToken=history-page-two') && str_contains($request->url(), 'startHistoryId=100'));
    }

    public function test_false_storage_put_keeps_message_pending_and_checkpoint_uncommitted(): void
    {
        $i = $this->installation();
        $this->fake(['m1' => $this->message()]);
        $localDisk = Storage::disk('local');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($localDisk);
        Storage::shouldReceive('disk')->with('kb')->andReturn($disk);
        try {
            $this->app->make(GmailConnector::class)->syncFull($i->id);
            self::fail('A failed source write cannot advance sync.');
        } catch (ConnectorApiException $exception) {
            self::assertSame('Gmail source write failed.', $exception->getMessage());
        }
        self::assertSame([], $this->bridge->documents);
        $state = $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state');
        self::assertNull($state['committed']);
        self::assertSame(0, $state['pending']['offset']);
    }

    public function test_missing_selected_label_fails_without_scope_fallback(): void
    {
        $i = $this->installation(config: ['folders' => ['include' => ['removed-label']]]);
        $this->fake(['m1' => $this->message()]);
        try {
            $this->app->make(GmailConnector::class)->syncFull($i->id);
            self::fail('Missing selection cannot fall back to all mail.');
        } catch (ConnectorApiException $exception) {
            self::assertStringContainsString('label is unavailable', $exception->getMessage());
        }
        self::assertSame([], $this->bridge->documents);
        self::assertNull($this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state'));
    }

    public function test_widened_selection_backfills_previously_excluded_messages(): void
    {
        $i = $this->installation(config: ['folders' => ['include' => ['Label_1']]]);
        $this->fake(['m1' => $this->message('m1', ['Label_1']), 'm2' => $this->message('m2', ['Label_2'])]);
        $connector = $this->app->make(GmailConnector::class);
        $connector->syncFull($i->id);
        self::assertCount(1, $this->bridge->documents);
        $i->update(['config_json' => ['folders' => ['include' => ['Label_1', 'Label_2']]]]);
        $connector->syncIncremental($i->id, null);
        self::assertCount(2, $this->bridge->documents);
        self::assertSame('m2', $this->bridge->documents[1]['metadata']['email_message_id']);
    }

    public function test_request_budget_preserves_pending_state_and_resumes_without_duplicates(): void
    {
        $i = $this->installation();
        $this->fake(['m1' => $this->message()]);
        config()->set('connector-gmail.sync.max_requests', 4);
        $connector = $this->app->make(GmailConnector::class);
        $first = $connector->syncFull($i->id);
        self::assertTrue($first->hasErrors());
        self::assertCount(1, $this->bridge->documents);
        self::assertNull($this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['committed']);
        config()->set('connector-gmail.sync.max_requests', 100);
        $second = $connector->syncIncremental($i->id, null);
        self::assertFalse($second->hasErrors());
        self::assertCount(1, $this->bridge->documents);
    }

    public function test_disconnect_clears_only_installation_state_and_keeps_ingested_sources(): void
    {
        $first = $this->installation();
        $second = $this->installation();
        $this->fake(['m1' => $this->message()]);
        $connector = $this->app->make(GmailConnector::class);
        $connector->syncFull($first->id);
        $connector->syncFull($second->id);
        self::assertCount(2, Storage::disk('local')->allFiles());
        self::assertCount(2, Storage::disk('kb')->allFiles());
        $connector->disconnect($first->id);
        self::assertCount(1, Storage::disk('local')->allFiles());
        self::assertStringContainsString('/'.$second->id.'/', Storage::disk('local')->allFiles()[0]);
        self::assertCount(2, Storage::disk('kb')->allFiles());
        self::assertNull($this->app->make(OAuthCredentialVault::class)->getCredentialRow($first->id));
        self::assertNotNull($this->app->make(OAuthCredentialVault::class)->getCredentialRow($second->id));
    }

    public function test_external_body_part_fetches_complete_content_and_oversized_file_is_not_fetched(): void
    {
        $i = $this->installation();
        $this->freshHttp();
        $message = $this->message();
        $message['payload']['mimeType'] = 'multipart/mixed';
        $message['payload']['body'] = [];
        $message['payload']['parts'] = [
            ['partId' => 'body', 'mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body-external', 'size' => 25]],
            ['partId' => 'file', 'filename' => 'huge.pdf', 'mimeType' => 'application/pdf', 'body' => ['attachmentId' => 'too-large-file', 'size' => 26214401]],
        ];
        Http::fake(function ($request) use ($message) {
            $url = $request->url();
            if (str_contains($url, '/labels')) {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox']]]);
            }
            if (str_contains($url, '/profile')) {
                return Http::response(['historyId' => '100']);
            }
            if (str_contains($url, '/history')) {
                return Http::response(['historyId' => '200']);
            }
            if (str_contains($url, '/attachments/body-external')) {
                return Http::response(['data' => rtrim(strtr(base64_encode('Complete externally stored body'), '+/', '-_'), '=')]);
            }
            if (str_contains($url, '/messages/m1')) {
                return Http::response($message);
            }

            return Http::response(['messages' => [['id' => 'm1']]]);
        });
        $events = [];
        Event::listen('askmydocs.email.prepared.v1', function ($envelope) use (&$events): void {
            $events[] = $envelope;
        });
        $result = $this->app->make(GmailConnector::class)->syncFull($i->id);
        self::assertFalse($result->hasErrors());
        self::assertCount(1, $this->bridge->documents);
        self::assertStringContainsString('Complete externally stored body', Storage::disk('kb')->get($this->bridge->documents[0]['relativePath']));
        self::assertSame('oversize', $events[0]['manifest'][0]['reason']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/attachments/too-large-file'));
    }

    public function test_txt_allowlist_excludes_pdf_and_trailing_default_extensions(): void
    {
        $i = $this->installation(config: ['attachments' => ['allowed_extensions' => ['txt']]]);
        $parts = [
            ['partId' => 'body', 'mimeType' => 'text/plain', 'body' => ['data' => 'Ym9keQ']],
            ['partId' => 'a', 'filename' => 'accepted.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 3, 'data' => 'b25l']],
            ['partId' => 'b', 'filename' => 'excluded.pdf', 'mimeType' => 'application/pdf', 'body' => ['size' => 3, 'data' => 'dHdv']],
            ['partId' => 'c', 'filename' => 'excluded.docx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'body' => ['size' => 3, 'data' => 'dHdv']],
        ];
        $this->fake(['m1' => $this->message(parts: $parts)]);
        $prepared = [];
        Event::listen('askmydocs.email.prepared.v1', function (array $envelope) use (&$prepared): void {
            $prepared[] = $envelope;
        });
        $result = $this->app->make(GmailConnector::class)->syncFull($i->id);
        self::assertFalse($result->hasErrors());
        self::assertCount(2, $this->bridge->documents);
        self::assertSame(['pending', 'excluded', 'excluded'], array_column($prepared[0]['manifest'], 'status'));
        self::assertSame([null, 'unsupported_extension', 'unsupported_extension'], array_column($prepared[0]['manifest'], 'reason'));
    }

    public function test_false_flags_override_defaults_without_changing_mailbox_state(): void
    {
        config()->set('connector-gmail.defaults.filters.only_unseen', true);
        config()->set('connector-gmail.defaults.filters.only_flagged', true);
        $i = $this->installation(config: ['filters' => ['only_unseen' => false, 'only_flagged' => false, 'skip_auto_generated' => false], 'body' => ['strip_quoted' => true], 'attachments' => ['enabled' => false]]);
        $message = $this->message(labels: ['INBOX']);
        $message['payload']['headers'][] = ['name' => 'Auto-Submitted', 'value' => 'auto-generated'];
        $message['payload']['body']['data'] = rtrim(strtr(base64_encode("Kept body\n> Quoted body"), '+/', '-_'), '=');
        $this->fake(['m1' => $message]);
        $result = $this->app->make(GmailConnector::class)->syncFull($i->id);
        self::assertFalse($result->hasErrors());
        self::assertCount(1, $this->bridge->documents);
        $body = Storage::disk('kb')->get($this->bridge->documents[0]['relativePath']);
        self::assertStringContainsString('Kept body', $body);
        self::assertStringNotContainsString('Quoted body', $body);
        self::assertSame(['INBOX'], $this->bridge->documents[0]['metadata']['email_folder_ids']);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_sync_status_exposes_completion_without_cursors_or_requests(): void
    {
        $i = $this->installation();
        $connector = $this->app->make(GmailConnector::class);
        self::assertSame(['started' => false, 'complete' => false, 'active' => false, 'mode' => 'baseline', 'phase' => 'not_started'], $connector->syncStatus($i->id));
        Http::assertNothingSent();
        $this->fake(['m1' => $this->message()]);
        config()->set('connector-gmail.sync.max_messages', 1);
        $connector->syncFull($i->id);
        self::assertSame(['started' => true, 'complete' => false, 'active' => true, 'mode' => 'baseline', 'phase' => 'catchup'], $connector->syncStatus($i->id));
        config()->set('connector-gmail.sync.max_messages', 100);
        $connector->syncIncremental($i->id, null);
        $before = $this->app->make(OAuthCredentialVault::class)->getExtra($i->id);
        $requests = count(Http::recorded());
        $status = $connector->syncStatus($i->id);
        self::assertSame(['started' => true, 'complete' => true, 'active' => false, 'mode' => 'baseline', 'phase' => 'idle'], $status);
        self::assertSame($before, $this->app->make(OAuthCredentialVault::class)->getExtra($i->id));
        self::assertSame($requests, count(Http::recorded()));
        self::assertStringNotContainsString('18446744073709551615', json_encode($status));
        $i->update(['config_json' => ['sync_window_days' => 30]]);
        self::assertFalse($connector->syncStatus($i->id)['complete']);
        self::assertFalse($connector->syncStatus($i->id)['started']);
    }

    public function test_sync_audit_counts_discovered_filtered_and_processed_candidates(): void
    {
        $i = $this->installation(config: ['folders' => ['include' => ['INBOX']]]);
        $this->fake(['m1' => $this->message('m1', ['INBOX']), 'm2' => $this->message('m2', ['Label_1'])]);
        $this->app->make(GmailConnector::class)->syncFull($i->id);
        $audit = $this->bridge->audits[0]['metadata'];
        self::assertSame(2, $audit['discovered']);
        self::assertSame(2, $audit['processed']);
        self::assertSame(1, $audit['skipped']);
        self::assertSame(0, $audit['unchanged']);
        self::assertSame(2, $audit['pages']);
        self::assertTrue($audit['complete']);
    }

    private function fakeListingPayload(array $payload): void
    {
        $this->freshHttp();
        Http::fake(function ($request) use ($payload) {
            $url = $request->url();
            if (str_contains($url, '/labels')) {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox']]]);
            }
            if (str_contains($url, '/profile')) {
                return Http::response(['historyId' => '100']);
            }
            if (str_contains($url, '/history')) {
                return Http::response(['historyId' => '200']);
            }

            return Http::response($payload);
        });
    }

    public function test_malformed_full_listing_cannot_authorize_prune(): void
    {
        $i = $this->installation(config: ['reconcile_deletions' => true]);
        $connector = $this->app->make(GmailConnector::class);
        $this->fake(['m1' => $this->message()]);
        $connector->syncFull($i->id);
        $this->fakeListingPayload(['unexpected' => true]);
        try {
            $connector->syncFull($i->id);
            self::fail('Malformed full listing cannot be treated as an empty mailbox.');
        } catch (ConnectorApiException $exception) {
            self::assertStringContainsString('Malformed Gmail message list', $exception->getMessage());
        }
        self::assertSame([], $this->bridge->deleted);
        self::assertCount(1, Storage::disk('kb')->allFiles());
        self::assertCount(1, Storage::disk('local')->allFiles());
        self::assertFalse($connector->syncStatus($i->id)['complete']);
        self::assertSame('full', $connector->syncStatus($i->id)['phase']);
        self::assertNull($this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['committed']);
    }

    public function test_empty_official_message_list_fixture_completes_normally(): void
    {
        $i = $this->installation();
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/empty-message-list.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->fakeListingPayload($fixture);
        $connector = $this->app->make(GmailConnector::class);
        $result = $connector->syncFull($i->id);
        self::assertFalse($result->hasErrors());
        self::assertSame([], $this->bridge->documents);
        self::assertTrue($connector->syncStatus($i->id)['complete']);
        self::assertSame([], $this->bridge->deleted);
    }

    public function test_project_rebind_redelivers_body_and_attachments_to_new_project(): void
    {
        $i = $this->installation();
        $parts = [
            ['partId' => 'body', 'mimeType' => 'text/plain', 'body' => ['data' => 'Ym9keQ']],
            ['partId' => 'attachment', 'filename' => 'report.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 3, 'data' => 'b25l']],
        ];
        $this->fake(['m1' => $this->message(parts: $parts)]);
        $connector = $this->app->make(GmailConnector::class);
        $connector->syncFull($i->id);
        self::assertCount(2, $this->bridge->documents);
        self::assertTrue($connector->syncStatus($i->id)['complete']);
        $i->update(['project_key' => 'project-b']);
        self::assertFalse($connector->syncStatus($i->id)['complete']);
        $result = $connector->syncIncremental($i->id, null);
        self::assertFalse($result->hasErrors());
        self::assertCount(4, $this->bridge->documents);
        self::assertSame(['project-a', 'project-a', 'project-b', 'project-b'], array_column($this->bridge->documents, 'projectKey'));
        self::assertSame($this->bridge->documents[0]['metadata']['email_message_key'], $this->bridge->documents[2]['metadata']['email_message_key']);
        self::assertSame($this->bridge->documents[1]['relativePath'], $this->bridge->documents[3]['relativePath']);
        self::assertTrue($connector->syncStatus($i->id)['complete']);
        $connector->syncFull($i->id);
        self::assertCount(4, $this->bridge->documents);
    }

    public function test_cyclic_full_continuation_fails_without_pruning_existing_sources(): void
    {
        $i = $this->installation(config: ['reconcile_deletions' => true]);
        $connector = $this->app->make(GmailConnector::class);
        $this->fake(['m1' => $this->message()]);
        $connector->syncFull($i->id);
        $this->fake(['m2' => $this->message('m2')], next: 'repeated-token');
        try {
            $connector->syncFull($i->id);
            self::fail('A repeated page token cannot be resumed forever.');
        } catch (ConnectorApiException $exception) {
            self::assertSame('Cyclic Gmail continuation.', $exception->getMessage());
        }
        self::assertSame([], $this->bridge->deleted);
        self::assertCount(2, Storage::disk('kb')->allFiles());
        self::assertFalse($connector->syncStatus($i->id)['complete']);
    }

    public function test_continuation_hard_cap_retains_pending_scope_and_is_an_explicit_error(): void
    {
        $i = $this->installation(config: ['reconcile_deletions' => true]);
        config()->set('connector-gmail.sync.max_continuations', 2);
        $this->freshHttp();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/labels')) {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox']]]);
            }
            if (str_contains($url, '/profile')) {
                return Http::response(['historyId' => '100']);
            }
            if (preg_match('~/messages/([^/?]+)~', $url, $matches)) {
                return Http::response($this->message($matches[1]));
            }
            $page = isset($request['pageToken']) ? (int) substr($request['pageToken'], 1) + 1 : 1;

            return Http::response(['messages' => [['id' => 'm'.$page]], 'nextPageToken' => 'p'.$page]);
        });
        try {
            $this->app->make(GmailConnector::class)->syncFull($i->id);
            self::fail('A continuation round must have a finite bound.');
        } catch (ConnectorApiException $exception) {
            self::assertStringContainsString('continuation limit exceeded', $exception->getMessage());
        }
        $state = $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state');
        self::assertSame('full', $state['phase']);
        self::assertNull($state['committed']);
        self::assertSame('p2', $state['token']);
        self::assertCount(2, $state['recent_tokens']);
        self::assertSame([], $this->bridge->deleted);
        self::assertCount(2, $this->bridge->documents);
    }

    public function test_label_rename_without_history_events_updates_presentation_and_reuses_identity(): void
    {
        $i = $this->installation();
        $connector = $this->app->make(GmailConnector::class);
        $message = $this->message(labels: ['INBOX', 'Label_1']);
        $this->fake(['m1' => $message]);
        $connector->syncFull($i->id);
        $first = $this->bridge->documents[0];
        $before = $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['labels_hash'];
        $this->fake(['m1' => $message], projectLabelName: 'Renamed projects');
        $result = $connector->syncIncremental($i->id, null);
        self::assertFalse($result->hasErrors());
        self::assertSame(1, $result->documentsUpdated);
        self::assertCount(2, $this->bridge->documents);
        $second = $this->bridge->documents[1];
        self::assertSame(['Inbox', 'Renamed projects'], $second['metadata']['email_folder_names']);
        self::assertSame($first['metadata']['email_message_key'], $second['metadata']['email_message_key']);
        self::assertSame($first['metadata']['email_revision_hash'], $second['metadata']['email_revision_hash']);
        self::assertSame($first['relativePath'], $second['relativePath']);
        self::assertNotSame($before, $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_sync_state')['labels_hash']);
        self::assertSame('Renamed projects', $this->app->make(OAuthCredentialVault::class)->getExtraKey($i->id, 'gmail_labels')['Label_1']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages?'));
        $snapshot = $this->app->make(OAuthCredentialVault::class)->getExtra($i->id);
        $connector->health($i->id);
        $connector->listAvailableFolders($i->id);
        self::assertSame($snapshot, $this->app->make(OAuthCredentialVault::class)->getExtra($i->id));
        $connector->syncIncremental($i->id, null);
        self::assertCount(2, $this->bridge->documents);
    }
}
