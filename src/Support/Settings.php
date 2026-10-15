<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Support;

/** Installation maps inherit defaults; operator-selected lists replace them entirely. */
final class Settings
{
    /** @param array<string,mixed> $defaults
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $default = $defaults[$key] ?? null;
            if (is_array($default) && ! array_is_list($default) && is_array($value)
                && ($value === [] || ! array_is_list($value))) {
                $defaults[$key] = self::merge($default, $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /** @param array<string,mixed> $settings */
    public static function fingerprint(array $settings, string $projectKey): string
    {
        return hash('sha256', json_encode([$settings, $projectKey], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
