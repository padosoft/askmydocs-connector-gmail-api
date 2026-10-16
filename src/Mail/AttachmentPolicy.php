<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Mail;

final class AttachmentPolicy
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    /** @param array<string,mixed> $attachment */
    public function exclusion(array $attachment, int $accepted, int $bytes): ?string
    {
        if (! ($this->config['enabled'] ?? true)) {
            return 'disabled';
        }
        if (($this->config['skip_inline'] ?? true) && $attachment['inline']) {
            return 'inline';
        }
        if ($accepted >= max(0, (int) ($this->config['max_per_email'] ?? 20))) {
            return 'count_limit';
        }
        if ($attachment['size'] > max(0, (int) ($this->config['max_size_mb'] ?? 25)) * 1048576) {
            return 'oversize';
        }
        if ($bytes + $attachment['size'] > max(0, (int) ($this->config['max_message_bytes'] ?? 52428800))) {
            return 'message_byte_limit';
        }
        $extension = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, $this->config['allowed_extensions'] ?? ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'], true)) {
            return 'unsupported_extension';
        }
        $types = ['pdf' => ['application/pdf'], 'doc' => ['application/msword'], 'ppt' => ['application/vnd.ms-powerpoint'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'], 'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'rtf' => ['application/rtf', 'text/rtf'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'odt' => ['application/vnd.oasis.opendocument.text'], 'txt' => ['text/plain'], 'md' => ['text/plain', 'text/markdown'], 'csv' => ['text/csv', 'text/plain'], 'html' => ['text/html'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg']];
        if ($attachment['mime'] !== 'application/octet-stream' && isset($types[$extension]) && ! in_array($attachment['mime'], $types[$extension], true)) {
            return 'mime_mismatch';
        }

        return null;
    }
}
