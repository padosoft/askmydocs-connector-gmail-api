<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorGmail\Mail\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorGmail\Mail\MarkdownRenderer;
use Padosoft\AskMyDocsConnectorGmail\Mail\PayloadParser;
use Padosoft\AskMyDocsConnectorGmail\Support\EmailMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    public function test_base64url_padding_unicode_and_invalid_encoding(): void
    {
        $this->assertSame('✓', PayloadParser::decode('4pyT'));
        $this->assertSame('a', PayloadParser::decode('YQ'));
        $this->expectException(ConnectorApiException::class);
        PayloadParser::decode('a$');
    }

    public function test_multipart_charset_encoded_header_and_html_sanitizer(): void
    {
        $native = ['id' => 'MCase', 'payload' => ['mimeType' => 'multipart/alternative', 'headers' => [['name' => 'Subject', 'value' => '=?UTF-8?B?VGVzdCDinJM=?=']], 'parts' => [['mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset=ISO-8859-1']], 'body' => ['data' => rtrim(strtr(base64_encode("caf\xe9"), '+/', '-_'), '=')]], ['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode('<head>bad</head><script>evil()</script><style>bad</style><p>Hello <b>world</b></p>'), '+/', '-_'), '=')]]]]];
        $m = (new PayloadParser)->parse($native, fn () => throw new \RuntimeException);
        $this->assertSame('café', $m->text);
        $this->assertSame('Test ✓', $m->subject());
        $markdown = (new MarkdownRenderer)->render($m, false);
        $this->assertStringContainsString('Hello **world**', $markdown);
        $this->assertStringNotContainsString('evil', $markdown);
        $this->assertStringNotContainsString('<script>', $markdown);
    }

    public function test_identity_preserves_case_and_revision_excludes_receipt_states(): void
    {
        $this->assertNotSame(EmailMetadata::key('a', 1, 'sub', 'ID'), EmailMetadata::key('a', 1, 'sub', 'id'));
        $m = [['slot' => 0, 'id' => 'a', 'name' => 'report.txt', 'mime' => 'text/plain', 'hash' => hash('sha256', 'body'), 'status' => 'pending', 'reason' => null]];
        $a = EmailMetadata::revision('hash', $m);
        $m[0]['status'] = 'saved';
        $this->assertSame($a, EmailMetadata::revision('hash', $m));
        $m[0]['hash'] = hash('sha256', 'different');
        $this->assertNotSame($a, EmailMetadata::revision('hash', $m));
    }

    public function test_attachment_size_count_inline_and_mime_policy(): void
    {
        $p = new AttachmentPolicy(['max_per_email' => 1, 'max_size_mb' => 1]);
        $a = ['name' => 'x.pdf', 'mime' => 'application/pdf', 'inline' => false, 'size' => 1];
        $this->assertNull($p->exclusion($a, 0, 0));
        $this->assertSame('count_limit', $p->exclusion($a, 1, 0));
        $a['size'] = 1048577;
        $this->assertSame('oversize', $p->exclusion($a, 0, 0));
        $a['size'] = 1;
        $a['mime'] = 'application/javascript';
        $this->assertSame('mime_mismatch', $p->exclusion($a, 0, 0));
        $a['inline'] = true;
        $this->assertSame('inline', $p->exclusion($a, 0, 0));
    }

    public function test_charset_conversion_cannot_exceed_utf8_body_budget(): void
    {
        $message = ['id' => 'm1', 'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset=ISO-8859-1']], 'body' => ['data' => rtrim(strtr(base64_encode("\xe9\xe9"), '+/', '-_'), '=')]]];
        $this->expectException(ConnectorApiException::class);
        $this->expectExceptionMessage('UTF-8 body byte budget');
        (new PayloadParser)->parse($message, fn () => throw new \RuntimeException, 2);
    }

    public function test_recursive_full_payload_fixture_ignores_snippet(): void
    {
        $native = json_decode(file_get_contents(__DIR__.'/../Fixtures/multipart-message.json'), true, 512, JSON_THROW_ON_ERROR);
        $message = (new PayloadParser)->parse($native, fn () => throw new \RuntimeException('No external part expected.'));
        $this->assertSame('Complete plain body', $message->text);
        $this->assertCount(1, $message->attachments);
        $this->assertTrue($message->attachments[0]['inline']);
        $this->assertStringNotContainsString('Incomplete preview', (new MarkdownRenderer)->render($message));
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payload_structures_fail_explicitly(array $payload): void
    {
        $this->expectException(ConnectorApiException::class);
        $this->expectExceptionMessage('Malformed Gmail');
        (new PayloadParser)->parse(['id' => 'm1', 'payload' => $payload], fn () => throw new \RuntimeException('Malformed structures cannot fetch external bytes.'));
    }

    public static function malformedPayloads(): iterable
    {
        yield 'headers-string' => [['headers' => 'invalid']];
        yield 'headers-null' => [['headers' => null]];
        yield 'headers-map' => [['headers' => ['name' => 'Subject', 'value' => 'invalid']]];
        yield 'header-value-array' => [['headers' => [['name' => 'Subject', 'value' => []]]]];
        yield 'parts-string' => [['parts' => 'invalid']];
        yield 'parts-null' => [['parts' => null]];
        yield 'parts-map' => [['parts' => ['bad' => ['mimeType' => 'text/plain']]]];
        yield 'part-scalar' => [['parts' => ['invalid']]];
        yield 'part-list' => [['parts' => [[1, 2]]]];
        yield 'mime-type-array' => [['mimeType' => []]];
        yield 'mime-type-null' => [['mimeType' => null]];
        yield 'mime-type-invalid' => [['mimeType' => 'invalid']];
        yield 'filename-array' => [['filename' => []]];
        yield 'part-id-array' => [['partId' => []]];
        yield 'body-string' => [['mimeType' => 'text/plain', 'body' => 'invalid']];
        yield 'body-null' => [['mimeType' => 'text/plain', 'body' => null]];
        yield 'body-list' => [['mimeType' => 'text/plain', 'body' => [1, 2]]];
        yield 'body-data-array' => [['mimeType' => 'text/plain', 'body' => ['data' => []]]];
        yield 'body-data-null' => [['mimeType' => 'text/plain', 'body' => ['data' => null]]];
        yield 'attachment-id-array' => [['body' => ['attachmentId' => []]]];
        yield 'attachment-id-empty' => [['body' => ['attachmentId' => '']]];
        yield 'size-array' => [['body' => ['size' => []]]];
        yield 'size-negative' => [['body' => ['size' => -1]]];
        yield 'nested-malformed-body' => [['parts' => [['mimeType' => 'text/plain', 'body' => 'invalid']]]];
    }

    public function test_announced_external_body_size_is_checked_before_fetch(): void
    {
        $fetched = false;
        try {
            (new PayloadParser)->parse(['id' => 'm1', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'external', 'size' => 3]]], function () use (&$fetched): string {
                $fetched = true;

                return 'data';
            }, 2);
            self::fail('The announced body exceeds the byte budget.');
        } catch (ConnectorApiException) {
            self::assertFalse($fetched);
        }
    }
}
