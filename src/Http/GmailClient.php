<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Http;

use Padosoft\AskMyDocsConnectorGmail\Auth\GmailOAuth;

final class GmailClient
{
    private readonly GoogleHttp $http;

    public function __construct(private readonly GmailOAuth $oauth, ?GoogleHttp $http = null)
    {
        $this->http = $http ?? new GoogleHttp;
    }

    /** @return array<string,mixed> */
    public function profile(int $installationId, ?RequestBudget $budget = null): array
    {
        return $this->get($installationId, 'profile', [], $budget);
    }

    /** @return array<string,mixed> */
    public function labels(int $installationId, ?RequestBudget $budget = null): array
    {
        return $this->get($installationId, 'labels', [], $budget);
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function messages(int $installationId, array $query = [], ?RequestBudget $budget = null): array
    {
        $this->query($query, ['maxResults', 'pageToken', 'q', 'includeSpamTrash', 'labelIds']);

        return $this->get($installationId, 'messages', $query, $budget);
    }

    /** @return array<string,mixed> */
    public function message(int $installationId, string $messageId, ?RequestBudget $budget = null): array
    {
        $data = $this->get($installationId, 'messages/'.$this->id($messageId), ['format' => 'full'], $budget);
        if (($data['id'] ?? null) !== $messageId) {
            throw new GmailApiException(0, 'mismatched_message_identity');
        }

        return $data;
    }

    /** @return array<string,mixed> */
    public function attachment(int $installationId, string $messageId, string $attachmentId, ?RequestBudget $budget = null): array
    {
        $data = $this->get($installationId, 'messages/'.$this->id($messageId).'/attachments/'.$this->id($attachmentId), [], $budget);
        if (! is_string($data['data'] ?? null)) {
            throw new GmailApiException(0, 'missing_attachment_data');
        }

        return $data;
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function history(int $installationId, array $query, ?RequestBudget $budget = null): array
    {
        $this->query($query, ['startHistoryId', 'maxResults', 'pageToken', 'historyTypes', 'labelId']);
        if (! is_string($query['startHistoryId'] ?? null) || $query['startHistoryId'] === '') {
            throw new \InvalidArgumentException('History requires an opaque string startHistoryId.');
        }

        return $this->get($installationId, 'history', $query, $budget);
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function get(int $installationId, string $path, array $query, ?RequestBudget $budget): array
    {
        $budget ??= new RequestBudget;

        return $this->http->get('https://gmail.googleapis.com/gmail/v1/users/me/'.$path, $this->oauth->accessToken($installationId, $budget), $query, $budget);
    }

    private function id(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,4096}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Gmail native resource ID.');
        }

        return $id;
    }

    /** @param array<string,mixed> $query
     * @param  list<string>  $allowed
     */
    private function query(array $query, array $allowed): void
    {
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new \InvalidArgumentException('Unsupported Gmail query parameter.');
        }
        if (isset($query['pageToken']) && (! is_string($query['pageToken']) || strlen($query['pageToken']) > 16384)) {
            throw new \InvalidArgumentException('Invalid Gmail continuation token.');
        }
    }
}
