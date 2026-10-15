<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Mail;

final readonly class Message
{
    /** @param array<string,mixed> $native @param array<string,string> $headers @param list<array<string,mixed>> $attachments */
    public function __construct(public array $native, public array $headers, public string $text, public string $html, public array $attachments) {}

    public function id(): string
    {
        return (string) $this->native['id'];
    }

    public function subject(): string
    {
        return $this->headers['subject'] ?? '';
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_values($this->native['labelIds'] ?? []);
    }

    public function date(): ?string
    {
        $date = $this->native['internalDate'] ?? null;
        if (! is_string($date) || ! ctype_digit($date)) {
            return null;
        }

        return gmdate('c', (int) floor((float) $date / 1000));
    }

    /** @return list<string> */
    public function addresses(string $header): array
    {
        preg_match_all('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+/i', $this->headers[$header] ?? '', $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }
}
