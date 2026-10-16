<?php

declare(strict_types=1);

return [
    'oauth' => ['client_id' => env('GMAIL_CLIENT_ID'), 'client_secret' => env('GMAIL_CLIENT_SECRET'), 'redirect_uri' => env('GMAIL_REDIRECT_URI'), 'state_ttl_seconds' => 600],
    'http' => ['timeout_seconds' => 20, 'connect_timeout_seconds' => 5, 'max_attempts' => 3, 'retry_max_delay_seconds' => 30, 'max_response_bytes' => 41943040],
    'sync' => ['max_requests' => 500, 'max_bytes' => 104857600, 'time_budget_seconds' => 120, 'max_messages' => 100, 'max_pages' => 10, 'max_continuations' => 10000, 'page_size' => 100, 'max_body_bytes' => 33554432],
    'state_disk' => 'local',
    'defaults' => ['sync_window_days' => 0, 'folders' => ['include' => [], 'exclude' => [], 'include_spam' => false, 'include_trash' => false], 'filters' => ['only_unseen' => false, 'only_flagged' => false, 'skip_auto_generated' => true], 'body' => ['prefer_text' => true, 'strip_quoted' => false], 'attachments' => ['enabled' => true, 'allowed_extensions' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'], 'max_size_mb' => 25, 'max_per_email' => 20, 'skip_inline' => true, 'max_message_bytes' => 52428800], 'reconcile_deletions' => false],
];
