<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests\Support;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

final class FakeIngestionBridge implements ConnectorIngestionContract
{
    public array $documents = [];

    public array $audits = [];

    public array $deleted = [];

    public ?int $failAt = null;

    public function dispatchIngestion(string $projectKey, string $relativePath, string $disk, string $title, array $metadata, string $mimeType, string $tenantId): void
    {
        if ($this->failAt !== null && count($this->documents) === $this->failAt) {
            $this->failAt = null;
            throw new \RuntimeException('Synthetic dispatch failure');
        }
        $this->documents[] = compact('projectKey', 'relativePath', 'disk', 'title', 'metadata', 'mimeType', 'tenantId');
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return ['relative' => $relativePath, 'absolute' => $relativePath, 'disk' => 'kb'];
    }

    public function redactContent(string $content): string
    {
        return str_replace('secret@example.test', '[redacted]', $content);
    }

    public function emitAudit(string $connectorKey, string $eventType, ?int $installationId = null, ?array $metadata = null): void
    {
        $this->audits[] = compact('connectorKey', 'eventType', 'installationId', 'metadata');
    }

    public function softDeleteByRemoteId(ConnectorInstallation $installation, string $metadataKey, string $remoteId): bool
    {
        $this->deleted[] = ['installation' => $installation->id, 'tenant' => $installation->tenant_id, 'key' => $metadataKey, 'id' => $remoteId];

        return true;
    }
}
