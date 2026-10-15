<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Http;

/** A run-scoped budget. Never share this object between installations. */
final class RequestBudget
{
    private int $requests = 0;

    private int $bytes = 0;

    private readonly float $startedAt;

    private readonly int $maxRequests;

    private readonly int $maxBytes;

    private readonly float $timeBudgetSeconds;

    public function __construct(?int $maxRequests = null, ?int $maxBytes = null, ?float $timeBudgetSeconds = null)
    {
        $this->maxRequests = max(1, $maxRequests ?? (int) config('connector-gmail.sync.max_requests', 500));
        $this->maxBytes = max(1, $maxBytes ?? (int) config('connector-gmail.sync.max_bytes', 104857600));
        $this->timeBudgetSeconds = max(0.001, $timeBudgetSeconds ?? (float) config('connector-gmail.sync.time_budget_seconds', 120));
        $this->startedAt = hrtime(true) / 1e9;
    }

    public function beforeRequest(): void
    {
        $this->checkTime();
        if ($this->requests >= $this->maxRequests || $this->remainingBytes() === 0) {
            throw new BudgetExceededException('Gmail run request/byte budget reached; resume the pending batch.');
        }
        $this->requests++;
    }

    public function addBytes(int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Byte count cannot be negative.');
        }
        if ($bytes > $this->remainingBytes()) {
            throw new BudgetExceededException('Gmail run byte budget reached; resume the pending batch.');
        }
        $this->bytes += $bytes;
        $this->checkTime();
    }

    public function checkTime(): void
    {
        if ($this->elapsed() >= $this->timeBudgetSeconds) {
            throw new BudgetExceededException('Gmail run time budget reached; resume the pending batch.');
        }
    }

    public function remainingBytes(): int
    {
        return $this->maxBytes - $this->bytes;
    }

    public function remainingSeconds(): float
    {
        return max(0, $this->timeBudgetSeconds - $this->elapsed());
    }

    /** @return array{requests:int,bytes:int,elapsed_seconds:float} */
    public function statistics(): array
    {
        return ['requests' => $this->requests, 'bytes' => $this->bytes, 'elapsed_seconds' => round($this->elapsed(), 3)];
    }

    private function elapsed(): float
    {
        return hrtime(true) / 1e9 - $this->startedAt;
    }
}
