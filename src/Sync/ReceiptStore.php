<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Sync;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

final class ReceiptStore
{
    public function __construct(private string $disk, private string $root) {}

    public function path(string $id): string
    {
        return $this->root.'/receipts/'.substr(hash('sha256', $id), 0, 2).'/'.hash('sha256', $id).'.json';
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $path = $this->path($id);
        if (! Storage::disk($this->disk)->exists($path)) {
            return null;
        }
        try {
            $value = json_decode(Storage::disk($this->disk)->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConnectorApiException('Malformed Gmail durable receipt.');
        }
        if (! is_array($value)) {
            throw new ConnectorApiException('Malformed Gmail durable receipt.');
        }

        return $value;
    }

    /** @param array<string,mixed> $receipt */
    public function put(string $id, array $receipt): void
    {
        if (! Storage::disk($this->disk)->put($this->path($id), json_encode($receipt, JSON_THROW_ON_ERROR))) {
            throw new ConnectorApiException('Unable to persist Gmail receipt.');
        }
    }

    public function delete(string $id): void
    {
        if (Storage::disk($this->disk)->exists($this->path($id)) && ! Storage::disk($this->disk)->delete($this->path($id))) {
            throw new ConnectorApiException('Unable to delete Gmail receipt.');
        }
    }

    /** Streaming listing; does not materialize the mailbox. @return \Generator<int,array<string,mixed>> */
    public function all(): \Generator
    {
        $filesystem = Storage::disk($this->disk);
        if (! $filesystem instanceof FilesystemAdapter) {
            throw new ConnectorApiException('Gmail reconciliation requires a Laravel filesystem adapter.');
        }
        foreach ($filesystem->getDriver()->listContents($this->root.'/receipts', true) as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $value = json_decode(Storage::disk($this->disk)->get($entry->path()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($value) || ! is_string($value['id'] ?? null)) {
                throw new ConnectorApiException('Malformed Gmail reconciliation receipt.');
            }
            yield $value;
        }
    }
}
