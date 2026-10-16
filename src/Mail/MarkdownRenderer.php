<?php

// Adapted from Padosoft AskMyDocsConnectorImap EmailToMarkdown, Apache-2.0.
declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Mail;

use League\HTMLToMarkdown\HtmlConverter;

final class MarkdownRenderer
{
    public function render(Message $message, bool $preferText = true, bool $stripQuoted = false): string
    {
        $body = $message->text;
        if ((! $preferText || trim($body) === '') && trim($message->html) !== '') {
            $body = (new HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'script style head', 'hard_break' => true, 'use_autolinks' => false]))->convert($message->html);
        }
        if ($stripQuoted) {
            $body = implode("\n", array_filter(preg_split('/\r\n|\r|\n/', $body) ?: [], static fn (string $line): bool => ! str_starts_with(ltrim($line), '>')));
        }
        $lines = ['# '.str_replace(["\r", "\n"], ' ', $message->subject() ?: '(no subject)'), '', '| Field | Value |', '|------|--------|'];
        foreach (['from' => 'From', 'to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc', 'date' => 'Date', 'message-id' => 'Message-ID', 'in-reply-to' => 'In-Reply-To', 'references' => 'References'] as $key => $label) {
            if (isset($message->headers[$key])) {
                $lines[] = '| '.$label.' | '.str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $message->headers[$key]).' |';
            }
        }

        return implode("\n", $lines)."\n\n---\n\n".trim($body)."\n";
    }
}
