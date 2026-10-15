<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Tests\Unit;

use Padosoft\AskMyDocsConnectorGmail\Support\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function test_list_overrides_replace_defaults_and_empty_list_stays_empty(): void
    {
        $defaults = ['attachments' => ['enabled' => true, 'allowed_extensions' => ['txt', 'pdf', 'docx']], 'folders' => ['include' => ['INBOX', 'STARRED'], 'exclude' => ['TRASH']]];
        $settings = Settings::merge($defaults, ['attachments' => ['allowed_extensions' => ['txt']], 'folders' => ['include' => ['Label_1'], 'exclude' => []]]);
        self::assertSame(['txt'], $settings['attachments']['allowed_extensions']);
        self::assertTrue($settings['attachments']['enabled']);
        self::assertSame(['Label_1'], $settings['folders']['include']);
        self::assertSame([], $settings['folders']['exclude']);
        self::assertSame([], Settings::merge($defaults, ['attachments' => ['allowed_extensions' => []]])['attachments']['allowed_extensions']);
    }

    public function test_nested_maps_preserve_explicit_false_zero_and_other_configuration(): void
    {
        $defaults = ['sync_window_days' => 30, 'filters' => ['only_unseen' => true, 'only_flagged' => true, 'skip_auto_generated' => true], 'body' => ['prefer_text' => true, 'strip_quoted' => false]];
        $overrides = ['sync_window_days' => 0, 'filters' => ['only_unseen' => false, 'only_flagged' => false, 'skip_auto_generated' => false], 'body' => ['prefer_text' => false], 'email_triage' => ['enabled' => false, 'profile_revision' => 7]];
        $settings = Settings::merge($defaults, $overrides);
        self::assertSame(0, $settings['sync_window_days']);
        self::assertSame($overrides['filters'], $settings['filters']);
        self::assertSame(['prefer_text' => false, 'strip_quoted' => false], $settings['body']);
        self::assertSame($overrides['email_triage'], $settings['email_triage']);
        self::assertSame($defaults, Settings::merge($defaults, ['filters' => [], 'body' => []]));
    }
}
