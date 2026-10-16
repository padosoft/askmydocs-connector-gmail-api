<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Mail;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

final class PayloadParser
{
    public static function decode(string $encoded): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $encoded) || strlen(rtrim($encoded, '=')) % 4 === 1) {
            throw new ConnectorApiException('Invalid Gmail base64url data.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if ($decoded === false) {
            throw new ConnectorApiException('Invalid Gmail base64url data.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    public static function headers(array $payload): array
    {
        $headers = [];
        $entries = array_key_exists('headers', $payload) ? $payload['headers'] : [];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new ConnectorApiException('Malformed Gmail header list.');
        }
        foreach ($entries as $h) {
            if (! is_array($h) || ! is_string($h['name'] ?? null) || $h['name'] === '' || ! is_string($h['value'] ?? null)) {
                throw new ConnectorApiException('Malformed Gmail headers.');
            }
            $name = strtolower($h['name']);
            $value = mb_decode_mimeheader($h['value']);
            $headers[$name] = isset($headers[$name]) ? $headers[$name].', '.$value : $value;
        }

        return $headers;
    }

    /** @param array<string,mixed> $native @param callable(string):string $fetchBody */
    public function parse(array $native, callable $fetchBody, int $maxBytes = 33554432): Message
    {
        if (! is_string($native['id'] ?? null) || $native['id'] === '' || ! is_array($native['payload'] ?? null)) {
            throw new ConnectorApiException('Malformed Gmail message.');
        }
        if (array_key_exists('labelIds', $native)) {
            if (! is_array($native['labelIds']) || ! array_is_list($native['labelIds'])) {
                throw new ConnectorApiException('Malformed Gmail label identity list.');
            }
            foreach ($native['labelIds'] as $label) {
                if (! is_string($label) || $label === '') {
                    throw new ConnectorApiException('Malformed Gmail label identity.');
                }
            }
        }
        foreach (['threadId', 'historyId', 'internalDate'] as $field) {
            if (array_key_exists($field, $native) && (! is_string($native[$field]) || $native[$field] === '')) {
                throw new ConnectorApiException('Malformed Gmail native message field.');
            }
        }
        if (isset($native['internalDate']) && ! ctype_digit($native['internalDate'])) {
            throw new ConnectorApiException('Malformed Gmail internal date.');
        }
        $plain = [];
        $html = [];
        $attachments = [];
        $bytes = 0;
        $parts = 0;
        $this->part($native['payload'], '0', $plain, $html, $attachments, $fetchBody, $bytes, $parts, $maxBytes, 0);
        usort($attachments, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return new Message($native, self::headers($native['payload']), implode("\n", $plain), implode("\n", $html), $attachments);
    }

    /** @param array<string,mixed> $part @param list<string> $plain @param list<string> $html @param list<array<string,mixed>> $attachments */
    private function part(array $part, string $path, array &$plain, array &$html, array &$attachments, callable $fetchBody, int &$bytes, int &$parts, int $limit, int $depth): void
    {
        if ($depth > 20 || ++$parts > 1000) {
            throw new ConnectorApiException('Gmail MIME depth/part budget exceeded.');
        }
        foreach (['mimeType', 'filename', 'partId'] as $field) {
            if (array_key_exists($field, $part) && ! is_string($part[$field])) {
                throw new ConnectorApiException('Malformed Gmail MIME part field.');
            }
        }
        $children = array_key_exists('parts', $part) ? $part['parts'] : [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new ConnectorApiException('Malformed Gmail MIME part list.');
        }
        foreach ($children as $child) {
            if (! is_array($child) || ($child !== [] && array_is_list($child))) {
                throw new ConnectorApiException('Malformed Gmail MIME part.');
            }
        }
        $headers = self::headers($part);
        $mime = strtolower($part['mimeType'] ?? 'application/octet-stream');
        if (! preg_match('~^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$~D', $mime)) {
            throw new ConnectorApiException('Malformed Gmail MIME type.');
        }
        $body = array_key_exists('body', $part) ? $part['body'] : [];
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ConnectorApiException('Malformed Gmail part body.');
        }
        if (array_key_exists('data', $body) && ! is_string($body['data'])) {
            throw new ConnectorApiException('Malformed Gmail part data.');
        }
        if (array_key_exists('attachmentId', $body) && (! is_string($body['attachmentId']) || $body['attachmentId'] === '')) {
            throw new ConnectorApiException('Malformed Gmail attachment identity.');
        }
        if (array_key_exists('size', $body) && (! is_int($body['size']) || $body['size'] < 0)) {
            throw new ConnectorApiException('Malformed Gmail part size.');
        }
        $id = (string) ($body['attachmentId'] ?? ($part['partId'] ?? $path));
        if ($id === '') {
            $id = $path;
        }
        $name = mb_decode_mimeheader((string) ($part['filename'] ?? ''));
        $disposition = strtolower($headers['content-disposition'] ?? '');
        $isAttachment = $name !== '' || str_starts_with($disposition, 'attachment') || isset($headers['content-id']) || $mime === 'message/rfc822';
        if ($isAttachment) {
            $attachments[] = ['id' => $id, 'download_id' => $body['attachmentId'] ?? null, 'name' => $name !== '' ? $name : 'part-'.$path.($mime === 'message/rfc822' ? '.eml' : '.bin'), 'mime' => $mime, 'size' => (int) ($body['size'] ?? 0), 'inline' => str_starts_with($disposition, 'inline') || isset($headers['content-id']), 'data' => $body['data'] ?? null];

            return;
        }
        if (in_array($mime, ['text/plain', 'text/html'], true)) {
            if (($body['size'] ?? 0) > $limit - $bytes) {
                throw new ConnectorApiException('Gmail body byte budget exceeded.');
            }
            $data = isset($body['data']) ? self::decode((string) $body['data']) : (isset($body['attachmentId']) ? $fetchBody((string) $body['attachmentId']) : '');
            $rawBytes = strlen($data);
            if ($bytes + $rawBytes > $limit) {
                throw new ConnectorApiException('Gmail body byte budget exceeded.');
            }
            $charset = 'UTF-8';
            if (preg_match('/charset\s*=\s*["\x27]?([^;"\x27\s]+)/i', $headers['content-type'] ?? '', $match)) {
                $charset = $match[1];
            }
            try {
                $data = mb_convert_encoding($data, 'UTF-8', $charset);
            } catch (\ValueError) {
                throw new ConnectorApiException('Unsupported Gmail body charset.');
            }
            $bytes += strlen($data);
            if ($bytes > $limit) {
                throw new ConnectorApiException('Gmail UTF-8 body byte budget exceeded.');
            }
            if ($mime === 'text/plain') {
                $plain[] = $data;
            } else {
                $html[] = $data;
            }
        }
        foreach ($children as $i => $child) {
            $this->part($child, $path.'.'.$i, $plain, $html, $attachments, $fetchBody, $bytes, $parts, $limit, $depth + 1);
        }
    }
}
