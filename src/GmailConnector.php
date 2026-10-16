<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorGmail\Auth\GmailOAuth;
use Padosoft\AskMyDocsConnectorGmail\Http\BudgetExceededException;
use Padosoft\AskMyDocsConnectorGmail\Http\GmailApiException;
use Padosoft\AskMyDocsConnectorGmail\Http\GmailClient;
use Padosoft\AskMyDocsConnectorGmail\Http\RequestBudget;
use Padosoft\AskMyDocsConnectorGmail\Mail\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorGmail\Mail\MarkdownRenderer;
use Padosoft\AskMyDocsConnectorGmail\Mail\Message;
use Padosoft\AskMyDocsConnectorGmail\Mail\MessageFilter;
use Padosoft\AskMyDocsConnectorGmail\Mail\PayloadParser;
use Padosoft\AskMyDocsConnectorGmail\Support\EmailMetadata;
use Padosoft\AskMyDocsConnectorGmail\Support\Settings;
use Padosoft\AskMyDocsConnectorGmail\Sync\ReceiptStore;

final class GmailConnector extends BaseConnector implements DeclaresProvenance, SupportsConnectionSettings, SupportsFolderDiscovery
{
    public function __construct(OAuthCredentialVault $vault, TenantContext $tenantContext, ConnectorIngestionContract $ingestion, private GmailOAuth $oauth, private GmailClient $client)
    {
        parent::__construct($vault, $tenantContext, $ingestion);
    }

    public function key(): string
    {
        return 'gmail';
    }

    public function displayName(): string
    {
        return 'Gmail';
    }

    public function oauthScopes(): array
    {
        return $this->oauth->scopes();
    }

    public function initiateOAuth(int $installationId): string
    {
        $this->loadInstallation($installationId);

        return $this->oauth->authorizationUrl($installationId);
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $installation = $this->loadInstallation($installationId);
        $mailbox = $this->vault->getExtraKey($installationId, 'gmail_mailbox_id');
        $this->withExistingSyncLock($installation, $mailbox, function () use ($installationId, $request): void {
            $this->oauth->callback($installationId, $request->query());
        });
        $this->emitAudit('installed', $installationId);
    }

    public function refreshTokenIfExpired(int $installationId): string
    {
        $this->loadInstallation($installationId);

        return $this->oauth->accessToken($installationId);
    }

    public function disconnect(int $installationId): void
    {
        $installation = $this->loadInstallation($installationId);
        $mailbox = $this->vault->getExtraKey($installationId, 'gmail_mailbox_id');
        $this->withExistingSyncLock($installation, $mailbox, function () use ($installationId, $installation): void {
            $root = '.connectors/gmail/'.hash('sha256', $installation->tenant_id).'/'.$installationId;
            $disk = Storage::disk((string) config('connector-gmail.state_disk', 'local'));
            if ($disk->exists($root) && ! $disk->deleteDirectory($root)) {
                throw new ConnectorApiException('Gmail local state cleanup failed; retry disconnect.');
            }
            $this->oauth->disconnect($installationId);
        });
        $this->emitAudit('disconnected', $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $this->loadInstallation($installationId);
        try {
            $this->oauth->mailboxId($installationId);
            $this->client->profile($installationId, new RequestBudget(3, 1048576, 5));

            return HealthStatus::healthy();
        } catch (ConnectorAuthException) {
            return HealthStatus::errored('Gmail authentication requires reconnect.');
        } catch (ConnectorApiException) {
            return HealthStatus::degraded('Gmail API unavailable; retry the health check.');
        }
    }

    public function provenanceTier(int $installationId): ProvenanceTier
    {
        $i = $this->loadInstallation($installationId);

        return ($i->config_json['provenance_tier'] ?? null) === ProvenanceTier::TrustedInternal->value ? ProvenanceTier::TrustedInternal : ProvenanceTier::UntrustedExternal;
    }

    public function listAvailableFolders(int $installationId): array
    {
        $this->loadInstallation($installationId);

        return array_keys($this->labelMap($installationId, new RequestBudget(3, 1048576, 10)));
    }

    /** @return array<string,string> */
    private function labelMap(int $installation, RequestBudget $budget): array
    {
        $data = $this->client->labels($installation, $budget);
        if (! is_array($data['labels'] ?? null)) {
            throw new ConnectorApiException('Malformed Gmail labels response.');
        }
        $map = [];
        foreach ($data['labels'] as $label) {
            if (! is_string($label['id'] ?? null) || ! is_string($label['name'] ?? null)) {
                throw new ConnectorApiException('Malformed Gmail label.');
            } $map[$label['id']] = $label['name'];
        }

        return $map;
    }

    /** @param array<string,string> $labels */
    private function labelsFingerprint(array $labels): string
    {
        ksort($labels, SORT_STRING);

        return hash('sha256', json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function connectionSettingsSchema(): array
    {
        $fields = [];
        foreach ([['sync_window_days', 'History window (days; 0 = all)', 'number', 0, 'Sync'], ['folders.include', 'Include label IDs', 'multiselect', [], 'Labels'], ['folders.exclude', 'Exclude label IDs', 'multiselect', [], 'Labels'], ['folders.include_spam', 'Include Spam', 'checkbox', false, 'Labels'], ['folders.include_trash', 'Include Trash', 'checkbox', false, 'Labels'], ['filters.only_unseen', 'Unread only', 'checkbox', false, 'Filters'], ['filters.only_flagged', 'Starred only', 'checkbox', false, 'Filters'], ['filters.skip_auto_generated', 'Skip auto-generated mail', 'checkbox', true, 'Filters'], ['filters.senders.include', 'Include senders or domains', 'tags', [], 'Filters'], ['filters.senders.exclude', 'Exclude senders or domains', 'tags', [], 'Filters'], ['filters.recipients.include', 'Include recipients or domains', 'tags', [], 'Filters'], ['filters.recipients.exclude', 'Exclude recipients or domains', 'tags', [], 'Filters'], ['filters.subject.include_keywords', 'Subject contains', 'tags', [], 'Filters'], ['filters.subject.exclude_keywords', 'Subject excludes', 'tags', [], 'Filters'], ['body.prefer_text', 'Prefer plain text', 'checkbox', true, 'Body'], ['body.strip_quoted', 'Strip quoted lines', 'checkbox', false, 'Body'], ['attachments.enabled', 'Import attachments', 'checkbox', true, 'Attachments'], ['attachments.skip_inline', 'Exclude inline files', 'checkbox', true, 'Attachments'], ['attachments.allowed_extensions', 'Allowed extensions', 'tags', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'], 'Attachments'], ['attachments.max_size_mb', 'Maximum file MiB', 'number', 25, 'Attachments'], ['attachments.max_per_email', 'Maximum files per email', 'number', 20, 'Attachments'], ['attachments.max_message_bytes', 'Attachment bytes per message', 'number', 52428800, 'Attachments'], ['reconcile_deletions', 'Reconcile local deletions', 'checkbox', false, 'Recovery']] as [$name,$label,$type,$default,$group]) {
            $fields[] = (new CredentialField($name, $label, $type, 'config', default: $default, group: $group, discovery: $type === 'multiselect' ? 'folders' : null))->toArray();
        }

        return $fields;
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->sync($installationId, true);
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        return $this->sync($installationId, false);
    }

    /** Local diagnostic state; never expose native cursors, tokens, account IDs or content.
     * @return array{started:bool,complete:bool,active:bool,mode:string,phase:string}
     */
    public function syncStatus(int $installationId): array
    {
        $installation = $this->loadInstallation($installationId);
        $state = $this->vault->getExtraKey($installationId, 'gmail_sync_state') ?? [];
        if (! is_array($state)) {
            throw new ConnectorApiException('Malformed Gmail sync state.');
        }
        $settings = Settings::merge((array) config('connector-gmail.defaults', []), $installation->config_json ?? []);
        $fingerprint = Settings::fingerprint($settings, $this->resolveProjectKey($installation));
        $mailbox = $this->vault->getExtraKey($installationId, 'gmail_mailbox_id');
        $knownLabels = $this->vault->getExtraKey($installationId, 'gmail_labels');
        $started = $state !== [] && is_string($mailbox) && ($state['mailbox'] ?? null) === $mailbox && ($state['config'] ?? null) === $fingerprint
            && is_array($knownLabels) && ($state['labels_hash'] ?? null) === $this->labelsFingerprint($knownLabels);
        $phase = $started ? ($state['phase'] ?? null) : 'not_started';
        if (! in_array($phase, ['not_started', 'full', 'catchup', 'prune', 'history', 'idle'], true)) {
            throw new ConnectorApiException('Malformed Gmail sync phase.');
        }
        $complete = $started && $phase === 'idle' && is_string($state['committed'] ?? null) && $state['committed'] !== '';
        $mode = $started ? ($state['mode'] ?? (in_array($phase, ['full', 'catchup', 'prune'], true) ? 'baseline' : 'incremental')) : 'baseline';
        if (! in_array($mode, ['baseline', 'incremental'], true)) {
            throw new ConnectorApiException('Malformed Gmail sync mode.');
        }

        return ['started' => $started, 'complete' => $complete, 'active' => $started && $phase !== 'idle', 'mode' => $mode, 'phase' => $phase];
    }

    private function sync(int $installationId, bool $full): SyncResult
    {
        $installation = $this->loadInstallation($installationId);
        $mailbox = $this->oauth->mailboxId($installationId);
        $lock = Cache::lock($this->syncLockKey($installation, $mailbox), max(600, (int) config('connector-gmail.sync.time_budget_seconds', 120) + 120));
        if (! $lock->get()) {
            throw new ConnectorApiException('Gmail sync already running for this installation.');
        }
        $added = 0;
        $updated = 0;
        $removed = 0;
        $processed = 0;
        $pages = 0;
        $discovered = 0;
        $skipped = 0;
        $unchanged = 0;
        $budget = new RequestBudget;
        try {
            if ($this->oauth->mailboxId($installationId) !== $mailbox) {
                throw new ConnectorAuthException('Gmail mailbox identity changed; retry with the current account.');
            }
            $config = Settings::merge((array) config('connector-gmail.defaults', []), $installation->config_json ?? []);
            $labels = $this->labelMap($installationId, $budget);
            foreach (array_merge($config['folders']['include'] ?? [], $config['folders']['exclude'] ?? []) as $label) {
                if (! isset($labels[$label])) {
                    throw new ConnectorApiException('Configured Gmail label is unavailable; update label selection.');
                }
            }
            $fingerprint = Settings::fingerprint($config, $this->resolveProjectKey($installation));
            $state = $this->vault->getExtraKey($installationId, 'gmail_sync_state') ?? [];
            if (! is_array($state)) {
                throw new ConnectorApiException('Malformed Gmail sync state.');
            }
            $labelsHash = $this->labelsFingerprint($labels);
            $this->vault->setExtraKey($installationId, 'gmail_labels', $labels);
            if (($state['mailbox'] ?? null) !== $mailbox || ($state['config'] ?? null) !== $fingerprint || ($state['labels_hash'] ?? null) !== $labelsHash || ($full && ($state['phase'] ?? 'idle') === 'idle')) {
                $profile = $this->client->profile($installationId, $budget);
                $anchor = $this->historyId($profile);
                $state = ['mailbox' => $mailbox, 'config' => $fingerprint, 'labels_hash' => $labelsHash, 'phase' => 'full', 'mode' => 'baseline', 'anchor' => $anchor, 'committed' => null, 'token' => null, 'pending' => null, 'generation' => (string) Str::uuid(), 'recent_tokens' => []];
                $this->saveState($installationId, $state);
            }
            $root = '.connectors/gmail/'.hash('sha256', $installation->tenant_id).'/'.$installationId.'/'.hash('sha256', $mailbox);
            $store = new ReceiptStore((string) config('connector-gmail.state_disk', 'local'), $root);
            while (true) {
                $budget->checkTime();
                if ($processed >= max(1, (int) config('connector-gmail.sync.max_messages', 100)) || $pages >= max(1, (int) config('connector-gmail.sync.max_pages', 10))) {
                    return $this->outcome($installationId, $added, $updated, $removed, ['Gmail batch paused; resume required.'], $budget, $state, ['discovered' => $discovered, 'processed' => $processed, 'skipped' => $skipped, 'unchanged' => $unchanged, 'pages' => $pages]);
                }
                if ($state['phase'] === 'prune') {
                    if ($config['reconcile_deletions'] ?? false) {
                        foreach ($store->all() as $receipt) {
                            $budget->checkTime();
                            if (($receipt['generation'] ?? null) !== $state['generation']) {
                                $removed += $this->remove($installation, $mailbox, $receipt['id'], $store);
                            }
                        }
                    }
                    $state['phase'] = 'idle';
                    $state['anchor'] = null;
                    $state['generation'] = null;
                    $this->saveState($installationId, $state);

                    return $this->outcome($installationId, $added, $updated, $removed, [], $budget, $state, ['discovered' => $discovered, 'processed' => $processed, 'skipped' => $skipped, 'unchanged' => $unchanged, 'pages' => $pages]);
                }
                if ($state['phase'] === 'idle') {
                    $state['phase'] = 'history';
                    $state['mode'] = 'incremental';
                    $state['anchor'] = $state['committed'];
                    $state['token'] = null;
                    $state['pending'] = null;
                    $state['recent_tokens'] = [];
                    $this->saveState($installationId, $state);
                }
                if ($state['pending'] === null) {
                    try {
                        $query = ['maxResults' => min(500, max(1, (int) config('connector-gmail.sync.page_size', 100)))];
                        if ($state['token'] !== null) {
                            $query['pageToken'] = $state['token'];
                        }
                        if ($state['phase'] === 'full') {
                            $query['includeSpamTrash'] = 'true';
                            $data = $this->client->messages($installationId, $query, $budget);
                            if (! array_key_exists('messages', $data) && ($data['resultSizeEstimate'] ?? null) !== 0) {
                                throw new ConnectorApiException('Malformed Gmail message list: missing messages and empty estimate.');
                            }
                            $ids = $this->messageIds(array_key_exists('messages', $data) ? $data['messages'] : []);
                            $checkpoint = null;
                        } else {
                            $query['startHistoryId'] = $state['anchor'];
                            $data = $this->client->history($installationId, $query, $budget);
                            $ids = $this->historyMessages(array_key_exists('history', $data) ? $data['history'] : []);
                            $checkpoint = $this->historyId($data);
                        }
                    } catch (GmailApiException $e) {
                        if ($e->status !== 404 || $state['phase'] === 'full') {
                            throw $e;
                        }
                        $profile = $this->client->profile($installationId, $budget);
                        $state = array_replace($state, ['phase' => 'full', 'mode' => 'baseline', 'anchor' => $this->historyId($profile), 'token' => null, 'pending' => null, 'generation' => (string) Str::uuid(), 'recent_tokens' => []]);
                        $this->saveState($installationId, $state);

                        continue;
                    }
                    $next = $data['nextPageToken'] ?? null;
                    if ($next !== null && (! is_string($next) || $next === '')) {
                        throw new ConnectorApiException('Malformed Gmail continuation.');
                    }
                    if ($next !== null) {
                        $hash = hash('sha256', $next);
                        if (in_array($hash, $state['recent_tokens'], true)) {
                            throw new ConnectorApiException('Cyclic Gmail continuation.');
                        }
                        $maxContinuations = max(1, min(10000, (int) config('connector-gmail.sync.max_continuations', 10000)));
                        if (count($state['recent_tokens']) >= $maxContinuations) {
                            throw new ConnectorApiException('Gmail continuation limit exceeded; narrow the sync scope or raise the configured limit.');
                        }
                        $state['recent_tokens'][] = $hash;
                    }
                    $discovered += count($ids);
                    $state['pending'] = ['ids' => $ids, 'offset' => 0, 'next' => $next, 'checkpoint' => $checkpoint];
                    $this->saveState($installationId, $state);
                    $pages++;
                }
                $pending = $state['pending'];
                while ($pending['offset'] < count($pending['ids'])) {
                    if ($processed >= max(1, (int) config('connector-gmail.sync.max_messages', 100))) {
                        return $this->outcome($installationId, $added, $updated, $removed, ['Gmail batch paused; resume required.'], $budget, $state, ['discovered' => $discovered, 'processed' => $processed, 'skipped' => $skipped, 'unchanged' => $unchanged, 'pages' => $pages]);
                    }
                    $budget->checkTime();
                    $id = $pending['ids'][$pending['offset']];
                    try {
                        $native = $this->client->message($installationId, $id, $budget);
                    } catch (GmailApiException $e) {
                        if ($e->status !== 404) {
                            throw $e;
                        } $native = null;
                    }
                    if ($native === null) {
                        $skipped++;
                        if ($config['reconcile_deletions'] ?? false) {
                            $removed += $this->remove($installation, $mailbox, $id, $store);
                        }
                    } else {
                        $message = (new PayloadParser)->parse($native, fn (string $attachment): string => PayloadParser::decode((string) ($this->client->attachment($installationId, $id, $attachment, $budget)['data'] ?? '')), (int) config('connector-gmail.sync.max_body_bytes', 33554432));
                        if (! (new MessageFilter($config))->passes($message)) {
                            $skipped++;
                            if ($config['reconcile_deletions'] ?? false) {
                                $removed += $this->remove($installation, $mailbox, $id, $store);
                            }
                        } else {
                            $kind = $this->ingest($installation, $mailbox, $message, $labels, $config, $store, $state['generation'], $budget);
                            $added += (int) ($kind === 'added');
                            $updated += (int) ($kind === 'updated');
                            $unchanged += (int) ($kind === 'unchanged');
                        }
                    }
                    $processed++;
                    $pending['offset']++;
                    $state['pending'] = $pending;
                    $this->saveState($installationId, $state);
                }
                $state['token'] = $pending['next'];
                $state['pending'] = null;
                if ($state['token'] === null) {
                    if ($state['phase'] === 'full') {
                        $state['phase'] = 'catchup';
                        $state['recent_tokens'] = [];
                    } else {
                        $state['committed'] = $pending['checkpoint'];
                        $state['phase'] = $state['phase'] === 'catchup' ? 'prune' : 'idle';
                    }
                }
                $this->saveState($installationId, $state);
                if ($state['phase'] === 'idle') {
                    return $this->outcome($installationId, $added, $updated, $removed, [], $budget, $state, ['discovered' => $discovered, 'processed' => $processed, 'skipped' => $skipped, 'unchanged' => $unchanged, 'pages' => $pages]);
                }
            }
        } catch (BudgetExceededException) {
            return $this->outcome($installationId, $added, $updated, $removed, ['Gmail request/byte/time budget exhausted; resume required.'], $budget, $state ?? [], ['discovered' => $discovered, 'processed' => $processed, 'skipped' => $skipped, 'unchanged' => $unchanged, 'pages' => $pages]);
        } finally {
            $lock->release();
        }
    }

    private function syncLockKey(ConnectorInstallation $installation, string $mailbox): string
    {
        return 'gmail:sync:'.hash('sha256', $installation->tenant_id.':'.$installation->id.':'.$mailbox);
    }

    /** Reconnect/disconnect cannot replace the account while sync is reading its credentials. */
    private function withExistingSyncLock(ConnectorInstallation $installation, mixed $mailbox, callable $callback): void
    {
        if (! is_string($mailbox) || $mailbox === '') {
            $callback();

            return;
        }
        try {
            Cache::lock($this->syncLockKey($installation, $mailbox), 600)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new ConnectorApiException('Gmail sync is currently running; retry the account operation shortly.');
        }
    }

    /** @param array<string,mixed> $state */
    private function saveState(int $id, array $state): void
    {
        $this->vault->setExtraKey($id, 'gmail_sync_state', $state);
    }

    /** @param array<string,mixed> $data */
    private function historyId(array $data): string
    {
        if (! is_string($data['historyId'] ?? null) || $data['historyId'] === '') {
            throw new ConnectorApiException('Missing Gmail history checkpoint.');
        }

        return $data['historyId'];
    }

    /** @param mixed $messages @return list<string> */
    private function messageIds(mixed $messages): array
    {
        if (! is_array($messages)) {
            throw new ConnectorApiException('Malformed Gmail message list.');
        } $ids = [];
        foreach ($messages as $m) {
            if (! is_string($m['id'] ?? null) || $m['id'] === '') {
                throw new ConnectorApiException('Malformed Gmail message identity.');
            } $ids[] = $m['id'];
        }

        return array_values(array_unique($ids));
    }

    /** @param mixed $history @return list<string> */
    private function historyMessages(mixed $history): array
    {
        if (! is_array($history)) {
            throw new ConnectorApiException('Malformed Gmail history.');
        } $ids = [];
        foreach ($history as $entry) {
            if (! is_array($entry)) {
                throw new ConnectorApiException('Malformed Gmail history entry.');
            }
            foreach (['messagesAdded', 'messagesDeleted', 'labelsAdded', 'labelsRemoved'] as $type) {
                if (! is_array($entry[$type] ?? [])) {
                    throw new ConnectorApiException('Malformed Gmail history event.');
                } foreach ($entry[$type] ?? [] as $change) {
                    $ids = array_merge($ids, $this->messageIds([$change['message'] ?? null]));
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function remove(ConnectorInstallation $installation, string $mailbox, string $id, ReceiptStore $store): int
    {
        $deleted = $this->softDeleteByMetadataKey($installation, 'email_message_key', EmailMetadata::key($installation->tenant_id, $installation->id, $mailbox, $id));
        $store->delete($id);

        return (int) $deleted;
    }

    /** @param array<string,string> $labels @param array<string,mixed> $config */
    private function ingest(ConnectorInstallation $i, string $mailbox, Message $m, array $labels, array $config, ReceiptStore $store, ?string $generation, RequestBudget $budget): string
    {
        $body = $this->maybeRedactContent((new MarkdownRenderer)->render($m, (bool) ($config['body']['prefer_text'] ?? true), (bool) ($config['body']['strip_quoted'] ?? false)));
        $manifest = [];
        $files = [];
        $bytes = 0;
        $policy = new AttachmentPolicy($config['attachments'] ?? []);
        foreach ($m->attachments as $slot => $attachment) {
            $reason = $policy->exclusion($attachment, count($files), $bytes);
            $content = null;
            if ($reason === null) {
                $content = $attachment['data'] !== null ? PayloadParser::decode($attachment['data']) : ($attachment['download_id'] !== null ? PayloadParser::decode((string) ($this->client->attachment($i->id, $m->id(), $attachment['download_id'], $budget)['data'] ?? '')) : null);
                if ($content === null) {
                    throw new ConnectorApiException('Gmail accepted attachment has no content.');
                }
                $attachment['size'] = strlen($content);
                $reason = $policy->exclusion($attachment, count($files), $bytes);
                if ($content === '') {
                    $reason = 'empty';
                }
            }
            $manifest[] = ['slot' => $slot, 'id' => $attachment['id'], 'name' => $attachment['name'], 'mime' => $attachment['mime'], 'hash' => $reason === null ? hash('sha256', $content) : null, 'status' => $reason === null ? 'pending' : 'excluded', 'reason' => $reason];
            if ($reason === null) {
                $files[$slot] = $content;
                $bytes += strlen($content);
            }
        }
        $metadata = EmailMetadata::build($m, $i->tenant_id, $i->id, $mailbox, $labels, $body, $manifest);
        $metadata['provenance_tier'] = $this->provenanceTier($i->id)->value;
        $project = $this->resolveProjectKey($i);
        $fingerprint = hash('sha256', json_encode([$metadata, $project], JSON_THROW_ON_ERROR));
        $receipt = $store->get($m->id());
        if (($receipt['metadata_hash'] ?? null) === $fingerprint && ($receipt['complete'] ?? false) === true) {
            $receipt['generation'] = $generation;
            $store->put($m->id(), $receipt);

            return 'unchanged';
        }
        Event::dispatch('askmydocs.email.prepared.v1', [['schema_version' => 1, 'tenant_id' => $i->tenant_id, 'installation_id' => $i->id, 'project_key' => $project, 'metadata' => $metadata, 'email_date' => $m->date(), 'manifest' => $manifest]]);
        $progress = ($receipt['metadata_hash'] ?? null) === $fingerprint ? $receipt : [
            'id' => $m->id(),
            'metadata_hash' => $fingerprint,
            'revision' => $metadata['email_revision_hash'],
            'body_dispatched' => false,
            'attachment_slots' => [],
            'complete' => false,
            'was_existing' => ($receipt['complete'] ?? false) === true || ($receipt['was_existing'] ?? false) === true,
        ];
        $progress['generation'] = $generation;
        $store->put($m->id(), $progress);
        $path = 'gmail/'.$i->id.'/'.hash('sha256', $metadata['email_message_key']);
        if (($progress['body_dispatched'] ?? false) !== true) {
            $this->persist($project, $path.'/body.md', $body, $m->subject() ?: '(no subject)', $metadata, 'text/markdown', $i->tenant_id);
            $progress['body_dispatched'] = true;
            $store->put($m->id(), $progress);
        }
        foreach ($files as $slot => $content) {
            if (($progress['attachment_slots'][$slot] ?? false) === true) {
                continue;
            }
            $file = $manifest[$slot];
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $file['name']))) ?: 'attachment';
            $name = substr($name, 0, 160);
            if ($name === '.' || $name === '..') {
                $name = 'attachment';
            }
            $child = array_merge($metadata, ['email_attachment_id' => $file['id'], 'email_attachment_slot' => $slot, 'email_attachment_hash' => $file['hash'], 'attachment_filename' => $file['name'], 'attachment_of_message_id' => $m->id()]);
            $this->persist($project, $path.'/attachments/'.$slot.'-'.hash('sha256', $file['id']).'-'.$name, $content, $file['name'], $child, $file['mime'], $i->tenant_id);
            $progress['attachment_slots'][$slot] = true;
            $store->put($m->id(), $progress);
        }
        $progress['complete'] = true;
        $store->put($m->id(), $progress);

        return ($progress['was_existing'] ?? false) ? 'updated' : 'added';
    }

    /** @param array<string,mixed> $metadata */
    private function persist(string $project, string $path, string $bytes, string $title, array $metadata, string $mime, string $tenant): void
    {
        $resolved = $this->resolveKbSourcePath($path);
        if (! Storage::disk($resolved['disk'])->put($resolved['absolute'], $bytes)) {
            throw new ConnectorApiException('Gmail source write failed.');
        } $this->dispatchIngestion($project, $resolved['relative'], $resolved['disk'], $title, $metadata, $mime, $tenant);
    }

    /** @param list<string> $errors @param array<string,mixed> $state @param array<string,int> $metrics */
    private function outcome(int $id, int $added, int $updated, int $removed, array $errors, RequestBudget $budget, array $state, array $metrics): SyncResult
    {
        $result = new SyncResult($added, $updated, $removed, $errors, Carbon::now());
        $this->emitAudit('sync_completed', $id, array_merge($result->toArray(), $budget->statistics(), $metrics, ['phase' => $state['phase'] ?? null, 'complete' => ($state['phase'] ?? null) === 'idle']));

        return $result;
    }
}
