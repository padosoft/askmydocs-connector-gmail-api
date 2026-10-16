<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Http;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

final class GmailApiException extends ConnectorApiException
{
    public function __construct(public readonly int $status, public readonly string $reason = 'api_error', ?\Throwable $previous = null)
    {
        parent::__construct("Gmail API request failed ({$status}, {$reason}).", $status, $previous);
    }
}
