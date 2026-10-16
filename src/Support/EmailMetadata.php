<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Support;

use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorGmail\Mail\Message;

final class EmailMetadata
{
    public static function key(string $tenant, int $installation, string $mailbox, string $message): string
    {
        return 'gmail:'.hash('sha256', self::json([$tenant, $installation, 'gmail', $mailbox, $message]));
    }

    /** @param list<array<string,mixed>> $manifest */
    public static function revision(string $bodyHash, array $manifest): string
    {
        return hash('sha256', self::json([$bodyHash, array_map(static fn (array $f): array => [$f['slot'], $f['id'], $f['name'], $f['mime'], $f['hash'], $f['status'] === 'excluded' ? $f['reason'] : null], $manifest)]));
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,string> $labels @param list<array<string,mixed>> $manifest @return array<string,mixed> */
    public static function build(Message $m, string $tenant, int $installation, string $mailbox, array $labels, string $body, array $manifest): array
    {
        $key = self::key($tenant, $installation, $mailbox, $m->id());
        $base = ['connector' => 'gmail', 'installation_id' => $installation, 'email_schema_version' => 1, 'email_provider' => 'gmail', 'email_mailbox_id' => $mailbox, 'email_message_id' => $m->id(), 'email_message_key' => $key, 'gmail_doc_key' => $key, 'email_folder_ids' => $m->labels(), 'email_folder_names' => array_map(static fn (string $id): string => $labels[$id] ?? $id, $m->labels()), 'email_date' => $m->date(), 'email_attachment_count' => count($manifest), 'email_body_hash' => hash('sha256', $body), 'email_revision_hash' => self::revision(hash('sha256', $body), $manifest), 'email_subject' => $m->subject(), 'email_from' => $m->headers['from'] ?? '', 'email_to' => $m->headers['to'] ?? '', 'email_cc' => $m->headers['cc'] ?? '', 'email_bcc' => $m->headers['bcc'] ?? '', 'email_headers' => $m->headers, 'email_rfc_message_id' => $m->headers['message-id'] ?? null, 'email_in_reply_to' => $m->headers['in-reply-to'] ?? null, 'email_references' => $m->headers['references'] ?? null];
        $native = $m->native;
        unset($native['payload']);
        $native['headers'] = $m->headers;

        return (new SourceAwareMetadataBuilder)->build($base, 'gmail', $native, [], true, $m->date(), $m->addresses('from')[0] ?? null);
    }
}
